<?php
/**
 * @copyright Copyright (c) 2019 Thomas Pulzer <t.pulzer@kniel.de>
 *
 * @author Thomas Pulzer <t.pulzer@kniel.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace DB4D;

use PDO;

/** Forward-only statement. Rows are paged from the existing server cursor, never re-executed. */
class DB4DStatement implements \IteratorAggregate
{
    public string $resultType = '';
    public int $rowCount = 0;
    public readonly string $queryString;
    private ?int $statementID = null;
    private array $metadata = [];
    private array $page = [];
    private int $pageIndex = 0;
    private int $received = 0;
    private bool $executed = false;
    private array $bindings = [];
    private array $bindingTypes = [];
    private ?int $fetchMode = null;
    private array $fetchArgs = [];
    private array $lastError = ['00000', null, null];

    public function __construct(private DB4DDriver $driver, #[\SensitiveParameter] string $sql) { $this->queryString = $sql; }

    public function execute(#[\SensitiveParameter] ?array $parameters = null): bool
    {
        $this->lastError = ['00000', null, null];
        try {
            $sql = $this->getRawSql($parameters);
            $this->closeCursor();
            $this->rowCount = 0; $this->metadata = []; $this->resultType = ''; $this->executed = false;
            $result = $this->driver->executeSql($sql);
            $this->resultType = $result['type']; $this->rowCount = $result['rowCount'];
            $this->metadata = $result['metadata']; $this->statementID = $result['id'];
            $this->page = $result['rows']; $this->pageIndex = 0; $this->received = count($this->page);
            if ($this->received < $this->rowCount && $this->resultType !== 'Update-Count' && $this->statementID === null) {
                $this->driver->close();
                throw new DB4DException('4D omitted the cursor identifier for a paged result.', '08S01');
            }
            $this->executed = true;
            return true; // Zero-row SELECT and zero-row UPDATE are both successful executions.
        } catch (DB4DException $error) { $this->recordError($error); throw $error; }
    }

    private static function key(int|string $parameter): int|string
    {
        if (is_int($parameter)) {
            if ($parameter < 1) { throw new DB4DException('Positional bindings start at one.', 'HY093'); }
            return $parameter - 1;
        }
        $key = ltrim($parameter, ':');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $key)) { throw new DB4DException('Invalid parameter name.', 'HY093'); }
        return $key;
    }

    public function bindValue(int|string $parameter, #[\SensitiveParameter] mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $key = self::key($parameter);
        if ($type !== PDO::PARAM_LOB || !is_resource($value)) {
            Parameters::literal($value, $type);
        } elseif (get_resource_type($value) !== 'stream') {
            throw new DB4DException('A LOB resource must be a readable stream.', 'HY105');
        }
        unset($this->bindings[$key]);
        $this->bindings[$key] = $value; $this->bindingTypes[$key] = $type;
        return true;
    }
    public function bindParam(int|string $parameter, #[\SensitiveParameter] mixed &$value, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        if ($maxLength !== 0 || $driverOptions !== null || !in_array($type, [PDO::PARAM_STR, PDO::PARAM_INT, PDO::PARAM_BOOL, PDO::PARAM_NULL, PDO::PARAM_LOB], true)) {
            throw new DB4DException('Output parameters and driver-specific bindings are unsupported.', 'IM001');
        }
        $key = self::key($parameter); unset($this->bindings[$key]);
        $this->bindings[$key] =& $value; $this->bindingTypes[$key] = $type;
        return true;
    }
    public function getRawSql(#[\SensitiveParameter] ?array $parameters = null): string
    {
        $values = $parameters === null ? $this->bindings : Parameters::normalize($parameters);
        return Parameters::render($this->queryString, $values, $parameters === null ? $this->bindingTypes : []);
    }
    public static function parseArguments(#[\SensitiveParameter] mixed &$value): void { $value = Parameters::literal($value); }

    public static function validFetchMode(mixed $mode): bool
    {
        return is_int($mode) && in_array($mode, [PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH, PDO::FETCH_OBJ,
            PDO::FETCH_COLUMN, DB4DDriver::FETCH_ASSOC, DB4DDriver::FETCH_NUM, DB4DDriver::FETCH_BOTH], true);
    }
    private static function normalizeMode(int $mode): int
    {
        return match ($mode) { DB4DDriver::FETCH_ASSOC => PDO::FETCH_ASSOC, DB4DDriver::FETCH_NUM => PDO::FETCH_NUM,
            DB4DDriver::FETCH_BOTH => PDO::FETCH_BOTH, default => $mode };
    }
    private function validateMode(int $mode, array $args): void
    {
        if (!self::validFetchMode($mode) || ($args && ($mode !== PDO::FETCH_COLUMN || count($args) !== 1 || !is_int($args[0]) || $args[0] < 0))) {
            throw new DB4DException('Unsupported fetch mode or fetch argument.', 'IM001');
        }
    }
    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->validateMode($mode, $args); $this->fetchMode = $mode; $this->fetchArgs = $args;
        return true;
    }

    private function nextRow(): array|false
    {
        if (!$this->executed) { throw new DB4DException('Execute the statement before fetching.', 'HY010'); }
        if ($this->resultType === 'Update-Count') { return false; }
        if ($this->pageIndex >= count($this->page)) {
            if ($this->received >= $this->rowCount || $this->statementID === null) { return false; }
            try {
                $last = min($this->rowCount - 1, $this->received + $this->driver->settings()['page_size'] - 1);
                $result = $this->driver->fetchPage($this->statementID, $this->received, $last, $this->metadata);
                if ($result['rowCount'] !== $this->rowCount || $result['metadata'] !== $this->metadata || ($result['id'] !== null && $result['id'] !== $this->statementID)) {
                    $this->driver->close();
                    throw new DB4DException('4D changed result metadata during paging.', '08S01');
                }
                $this->page = $result['rows']; $this->pageIndex = 0; $this->received += count($this->page);
            } catch (DB4DException $error) { $this->recordError($error); throw $error; }
        }
        $row = $this->page[$this->pageIndex++];
        if ($this->driver->getAttribute(PDO::ATTR_STRINGIFY_FETCHES)) {
            foreach ($row as &$value) { if ($value !== null && !is_string($value)) { $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value; } }
        }
        return $row;
    }

    private function formatRow(array $row, int $mode, array $args): array|object|string|int|float|bool|null
    {
        $mode = self::normalizeMode($mode);
        if ($mode === PDO::FETCH_NUM) { return $row; }
        if ($mode === PDO::FETCH_COLUMN) {
            $index = $args[0] ?? 0;
            if (!array_key_exists($index, $row)) { throw new DB4DException('Column index is out of range.', '07009'); }
            return $row[$index];
        }
        $names = $this->readMetadata(); $assoc = [];
        foreach ($row as $i => $value) { $assoc[$names[$i]] = $value; }
        if ($mode === PDO::FETCH_OBJ) { return (object)$assoc; }
        return $mode === PDO::FETCH_BOTH ? $row + $assoc : $assoc;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($cursorOrientation !== PDO::FETCH_ORI_NEXT || $cursorOffset !== 0) { throw new DB4DException('Only forward-only fetching is supported.', 'IM001'); }
        $default = $mode === PDO::FETCH_DEFAULT;
        $mode = $default ? ($this->fetchMode ?? $this->driver->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)) : $mode;
        $args = $default ? $this->fetchArgs : [];
        $this->validateMode($mode, $args);
        $row = $this->nextRow();
        return $row === false ? false : $this->formatRow($row, $mode, $args);
    }
    public function fetchRow(int $style = DB4DDriver::FETCH_BOTH): mixed
    {
        $row = $this->fetch($style);
        return $row === false ? null : $row;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $useDefault = $mode === PDO::FETCH_DEFAULT;
        $mode = $useDefault ? ($this->fetchMode ?? $this->driver->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)) : $mode;
        if ($useDefault && !$args) { $args = $this->fetchArgs; }
        $this->validateMode($mode, $args); $result = [];
        while (($row = $this->nextRow()) !== false) { $result[] = $this->formatRow($row, $mode, $args); }
        return $result;
    }
    public function fetchColumn(int $index = 0): mixed
    {
        if ($index < 0 || ($this->executed && $index >= $this->columnCount())) { throw new DB4DException('Column index is out of range.', '07009'); }
        $row = $this->nextRow();
        return $row === false ? false : $row[$index];
    }
    public function getIterator(): \Traversable
    {
        // Do not use fetch() as the EOF condition: a valid FETCH_COLUMN value can be false.
        $mode = $this->fetchMode ?? $this->driver->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE);
        while (($row = $this->nextRow()) !== false) { yield $this->formatRow($row, $mode, $this->fetchArgs); }
    }
    public function closeCursor(): bool
    {
        $id = $this->statementID; $this->statementID = null; $this->page = []; $this->pageIndex = 0;
        $this->received = $this->rowCount;
        if ($id !== null && $this->driver->isOpen()) {
            try { $this->driver->closeStatement($id); }
            catch (DB4DException $error) { $this->recordError($error); throw $error; }
        }
        return true;
    }
    public function rowCount(): int { return $this->rowCount; }
    public function columnCount(): int { return count($this->metadata['types'] ?? []); }
    public function readMetadata(): array
    {
        $names = $this->metadata['names'] ?? [];
        return match ($this->driver->getAttribute(PDO::ATTR_CASE)) {
            PDO::CASE_UPPER => array_map(static fn($n) => mb_strtoupper($n, 'UTF-8'), $names),
            PDO::CASE_LOWER => array_map(static fn($n) => mb_strtolower($n, 'UTF-8'), $names), default => $names,
        };
    }
    public function getStatementID(): ?int { return $this->statementID; }
    public function errorCode(): string { return $this->lastError[0]; }
    public function errorInfo(): array { return $this->lastError; }
    public function errorOccured(): bool { return $this->errorCode() !== '00000'; }
    public function getErrorCode(): int { return $this->lastError[1] ?? 0; }
    public function getErrorDescription(): string { return $this->lastError[2] ?? ''; }
    private function recordError(DB4DException $error): void { $this->lastError = $error->errorInfo; $this->driver->recordError($error); }
    public function __destruct()
    {
        try { $this->closeCursor(); } catch (\Throwable) { /* Destruction must never hide an original exception. */ }
    }
}

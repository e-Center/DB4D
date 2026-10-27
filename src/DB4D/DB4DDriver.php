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

/** Pure PHP 4D connection. This is deliberately not a native PDO subclass. */
class DB4DDriver
{
    public const FETCH_NUM = 0xA0;
    public const FETCH_ASSOC = 0xA1;
    public const FETCH_BOTH = 0xA2;
    private ?Transport $connection = null;
    public int $commandID = 1;
    private bool $transaction = false;
    private array $lastError = ['00000', null, null];
    private array $attributes = [PDO::ATTR_CASE => PDO::CASE_NATURAL, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH, PDO::ATTR_STRINGIFY_FETCHES => false];
    private array $settings;

    public function __construct(string $host, int $port, string $user, #[\SensitiveParameter] string $pass, array $options = [])
    {
        if (PHP_INT_SIZE !== 8) { throw new DB4DException('DB4D requires a 64-bit PHP runtime.', 'IM001'); }
        $this->settings = ['connect_timeout' => 5.0, 'read_timeout' => 10.0, 'page_size' => 100,
            'max_field_bytes' => 16777216, 'max_page_bytes' => 33554432, 'timestamp_format' => 'pdo'];
        foreach ($options as $key => $value) {
            if (is_int($key)) { $this->setAttribute($key, $value); }
            elseif (array_key_exists($key, $this->settings)) { $this->settings[$key] = $value; }
            else { throw new DB4DException('Unknown DB4D option.', 'IM001'); }
        }
        foreach (['connect_timeout', 'read_timeout'] as $key) {
            if (!is_numeric($this->settings[$key]) || !is_finite((float)$this->settings[$key]) || $this->settings[$key] <= 0) {
                throw new DB4DException('Invalid DB4D timeout.', 'HY024');
            }
        }
        foreach (['page_size' => 10000, 'max_field_bytes' => 1073741824, 'max_page_bytes' => 1073741824] as $key => $limit) {
            if (!is_int($this->settings[$key]) || $this->settings[$key] < 1 || $this->settings[$key] > $limit) {
                throw new DB4DException('Invalid DB4D size limit.', 'HY024');
            }
        }
        if (!in_array($this->settings['timestamp_format'], ['pdo', 'legacy'], true)) {
            throw new DB4DException('Invalid timestamp format.', 'HY024');
        }
        $this->connection = Transport::connect($host, $port, (float)$this->settings['connect_timeout'], (float)$this->settings['read_timeout']);
        try {
            $this->exchange('LOGIN', ['USER-NAME-BASE64' => base64_encode($user),
                'USER-PASSWORD-BASE64' => base64_encode($pass), 'REPLY-WITH-BASE64-TEXT' => 'Y', 'PROTOCOL-VERSION' => '0.1a']);
        } catch (\Throwable $e) { $this->close(); throw $e; }
    }

    public static function fromDsn(string $dsn, string $user, #[\SensitiveParameter] string $pass, array $options = []): self
    {
        if (!preg_match('/^4d:(.*)$/iDs', $dsn, $match)) { throw new DB4DException('Expected a 4D DSN.', 'IM002'); }
        $fields = [];
        foreach (explode(';', $match[1]) as $part) {
            if ($part === '') { continue; }
            $pair = explode('=', $part, 2);
            $key = strtolower(trim($pair[0]));
            if (count($pair) !== 2 || !in_array($key, ['host', 'port', 'charset'], true) || isset($fields[$key])) {
                throw new DB4DException('Unsupported or duplicate 4D DSN field.', 'IM002');
            }
            $fields[$key] = trim($pair[1]);
        }
        if (isset($fields['charset']) && !in_array(strtoupper($fields['charset']), ['UTF-8', 'UTF8'], true)) {
            throw new DB4DException('DB4D requires UTF-8 application strings.', 'IM001');
        }
        if (!isset($fields['host']) || !ctype_digit($fields['port'] ?? '19812')) { throw new DB4DException('Invalid 4D DSN.', 'IM002'); }
        return new self($fields['host'], (int)($fields['port'] ?? 19812), $user, $pass, $options);
    }

    public function prepare(#[\SensitiveParameter] string $sql, array $options = []): DB4DStatement
    {
        $this->ensureOpen();
        if ($options && $options !== [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]) {
            throw new DB4DException('Only forward-only cursors are supported.', 'IM001');
        }
        return new DB4DStatement($this, $sql);
    }

    public function query(#[\SensitiveParameter] string $sql, ?int $fetchMode = null, mixed ...$args): DB4DStatement
    {
        $statement = $this->prepare($sql);
        if ($fetchMode !== null) { $statement->setFetchMode($fetchMode, ...$args); }
        $statement->execute();
        return $statement;
    }

    public function exec(#[\SensitiveParameter] string $sql): int
    {
        $statement = $this->query($sql);
        $count = $statement->rowCount();
        $statement->closeCursor();
        return $count;
    }

    public function quote(#[\SensitiveParameter] string $value, int $type = PDO::PARAM_STR): string
    {
        return Parameters::literal($value, $type);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $valid = match ($attribute) {
            PDO::ATTR_CASE => in_array($value, [PDO::CASE_NATURAL, PDO::CASE_UPPER, PDO::CASE_LOWER], true),
            PDO::ATTR_ERRMODE => $value === PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => DB4DStatement::validFetchMode($value),
            PDO::ATTR_STRINGIFY_FETCHES => is_bool($value),
            PDO::ATTR_EMULATE_PREPARES => $value === true,
            default => false,
        };
        if (!$valid) { throw new DB4DException('Unsupported PDO attribute or value; exceptions and emulated prepares are mandatory.', 'IM001'); }
        $this->attributes[$attribute] = $value;
        return true;
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => '4D', PDO::ATTR_CLIENT_VERSION => 'DB4D 2.0-dev',
            PDO::ATTR_EMULATE_PREPARES => true,
            default => $this->attributes[$attribute] ?? throw new DB4DException('Unsupported PDO attribute.', 'IM001'),
        };
    }

    public function beginTransaction(): bool
    {
        if ($this->transaction) { throw new DB4DException('A transaction is already active.', '25000'); }
        $this->exec('START');
        return true;
    }
    public function commit(): bool { return $this->finishTransaction('COMMIT'); }
    public function rollBack(): bool { return $this->finishTransaction('ROLLBACK'); }
    private function finishTransaction(string $command): bool
    {
        if (!$this->transaction) { throw new DB4DException('There is no active transaction.', '25000'); }
        $this->exec($command);
        return true;
    }
    public function inTransaction(): bool { return $this->transaction && $this->isOpen(); }
    public function lastInsertId(?string $name = null): never
    {
        throw new DB4DException('4D has no generic lastInsertId contract; select the application identifier explicitly.', 'IM001');
    }
    public function errorCode(): string { return $this->lastError[0]; }
    public function errorInfo(): array { return $this->lastError; }
    public function recordError(DB4DException $error): void { $this->lastError = $error->errorInfo; }
    public function settings(): array { return $this->settings; }
    public function isOpen(): bool { return $this->connection?->isOpen() ?? false; }
    private function ensureOpen(): void
    {
        if (!$this->isOpen()) { throw new DB4DException('The 4D connection is closed.', '08003'); }
    }

    /** Internal statement transport; request and complete response are consumed atomically. */
    public function executeSql(#[\SensitiveParameter] string $sql): array
    {
        if (strlen($sql) > $this->settings['max_field_bytes']) { throw new DB4DException('SQL exceeds its configured size limit.', 'HY001'); }
        $result = $this->exchange('EXECUTE-STATEMENT', ['STATEMENT-BASE64' => base64_encode($sql),
            'OUTPUT-MODE' => 'RELEASE', 'FIRST-PAGE-SIZE' => (string)$this->settings['page_size']]);
        $command = strtoupper(trim($sql));
        if ($command === 'START') { $this->transaction = true; }
        elseif (in_array($command, ['COMMIT', 'ROLLBACK'], true)) { $this->transaction = false; }
        return $result;
    }

    public function fetchPage(int $id, int $first, int $last, array $metadata): array
    {
        return $this->exchange('FETCH-RESULT', ['STATEMENT-ID' => (string)$id, 'COMMAND-INDEX' => '000',
            'FIRST-ROW-INDEX' => (string)$first, 'LAST-ROW-INDEX' => (string)$last, 'OUTPUT-MODE' => 'RELEASE'], $metadata, $last - $first + 1);
    }
    public function closeStatement(int $id): void { $this->exchange('CLOSE-STATEMENT', ['STATEMENT-ID' => (string)$id]); }

    private function exchange(string $command, #[\SensitiveParameter] array $fields, array $metadata = [], ?int $expectedRows = null): array
    {
        $this->ensureOpen();
        $this->lastError = ['00000', null, null];
        $id = $this->commandID++;
        $request = sprintf("%03d %s\r\n", $id, $command);
        foreach ($fields as $key => $value) { $request .= $key . ':' . $value . "\r\n"; }
        $request .= "\r\n";
        try {
            $this->connection->startOperation();
            $this->connection->write($request);
            $header = $this->readHeader($id);
            if (($header['command-count'] ?? '1') !== '1') { throw new DB4DException('Multiple SQL results are unsupported.', 'IM001'); }
            $type = $header['result-type'] ?? ($command === 'FETCH-RESULT' ? 'Result-Set' : '');
            if ($type === 'Update-Count') {
                // This eight-byte binary body is mandatory in lib4d_sql; consume even for zero rows.
                $count = Codec::signed64($this->connection->read(8));
                if ($count < 0) { throw new DB4DException('Invalid affected-row count.', '08S01'); }
                return ['type' => $type, 'rowCount' => $count, 'rows' => [], 'id' => null, 'metadata' => []];
            }
            if (in_array($type, ['Result-Set', 'Fetch-Result'], true)) {
                $metadata = $this->metadata($header, $metadata);
                $total = self::integer($header['row-count'] ?? (string)($metadata['total'] ?? -1));
                $sent = self::integer($header['row-count-sent'] ?? (string)($expectedRows ?? -1));
                if ($sent > $this->settings['page_size'] || $sent > $total || ($expectedRows !== null && $sent !== $expectedRows)) {
                    throw new DB4DException('Invalid 4D page length.', '08S01');
                }
                $metadata['total'] = $total;
                $rows = $this->readRows($metadata, $sent);
                return ['type' => $type, 'rowCount' => $total, 'rows' => $rows,
                    'id' => isset($header['statement-id']) ? self::integer($header['statement-id']) : null, 'metadata' => $metadata];
            }
            if ($type !== '' || in_array($command, ['EXECUTE-STATEMENT', 'FETCH-RESULT'], true)) {
                throw new DB4DException('Unexpected 4D result type.', '08S01');
            }
            return [];
        } catch (DB4DException $error) {
            $this->recordError($error);
            // A complete SQL ERROR frame leaves the channel usable. A partial or unknown body does not.
            if (!in_array($error->getCode(), ['42000', '28000'], true)) { $this->close(); }
            throw $error;
        }
    }

    private function readHeader(int $id): array
    {
        $line = $this->connection->line(65536);
        if (!preg_match('/^([0-9]+) +(OK|ERROR) *$/D', $line, $match) || (int)$match[1] !== $id) {
            throw new DB4DException('Unexpected 4D response identifier.', '08S01');
        }
        $header = []; $size = strlen($line) + 2;
        while (($line = $this->connection->line(65536)) !== '') {
            $size += strlen($line) + 2;
            if ($size > 65536 || !str_contains($line, ':')) { throw new DB4DException('Invalid 4D response header.', '08S01'); }
            [$key, $value] = explode(':', $line, 2); $key = strtolower(trim($key)); $value = trim($value);
            if (isset($header[$key])) { throw new DB4DException('Duplicate 4D response header.', '08S01'); }
            if (str_ends_with($key, '-base64')) {
                $key = substr($key, 0, -7); $value = base64_decode($value, true);
                if ($value === false || isset($header[$key])) { throw new DB4DException('Invalid base64 response header.', '08S01'); }
            }
            $header[$key] = $value;
        }
        if ($match[2] === 'ERROR') {
            $native = isset($header['error-code']) && preg_match('/^-?[0-9]+$/D', $header['error-code']) ? (int)$header['error-code'] : 0;
            // Never put SQL, parameters or server-echoed values into exceptions or logs.
            throw new DB4DException('4D rejected the request (server code ' . $native . ').', $id === 1 ? '28000' : '42000', $native);
        }
        return $header;
    }

    private static function integer(string $value): int
    {
        $normalized = ltrim($value, '0');
        $maximum = (string)PHP_INT_MAX;
        if (!ctype_digit($value) || strlen($normalized) > strlen($maximum) ||
            (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new DB4DException('Invalid protocol integer.', '08S01');
        }
        return (int)$value;
    }

    private function metadata(array $header, array $previous): array
    {
        $n = isset($header['column-count']) ? self::integer($header['column-count']) : count($previous['types'] ?? []);
        if ($n > 2048) { throw new DB4DException('Too many 4D columns.', '08S01'); }
        $types = isset($header['column-types']) ? preg_split('/ +/', trim($header['column-types'])) : ($previous['types'] ?? []);
        $aliases = $previous['names'] ?? [];
        if (isset($header['column-aliases'])) {
            preg_match_all('/\[((?:[^\]]|\]\])*)\]/', $header['column-aliases'], $match);
            $aliases = array_map(static fn($s) => str_replace(']]', ']', $s), $match[1]);
        }
        $updateable = isset($header['column-updateability']) ? in_array('Y', preg_split('/ +/', $header['column-updateability']), true) : ($previous['updateable'] ?? false);
        if (count($aliases) !== $n || count($types) !== $n) { throw new DB4DException('Inconsistent 4D column metadata.', '08S01'); }
        return ['names' => $aliases, 'types' => $types, 'updateable' => $updateable, 'total' => $previous['total'] ?? null];
    }

    private function readRows(array $metadata, int $count): array
    {
        $bytes = 0;
        $read = function(int $length) use (&$bytes): string {
            $bytes += $length;
            if ($length < 0 || $bytes > $this->settings['max_page_bytes']) { throw new DB4DException('4D page exceeds its configured byte limit.', '08S01'); }
            return $this->connection->read($length);
        };
        $rows = [];
        for ($r = 0; $r < $count; $r++) {
            if ($metadata['updateable']) {
                $status = $read(1);
                if ($status === '1') { $read(4); }
                elseif ($status !== '0') { throw new DB4DException('Invalid row identifier status.', '08S01'); }
            }
            $row = [];
            foreach ($metadata['types'] as $type) {
                $status = $read(1);
                $row[] = match ($status) {
                    '0' => null,
                    '1' => Codec::value($type, $read, $this->settings['max_field_bytes'], $this->settings['timestamp_format']),
                    default => throw new DB4DException('4D returned an invalid or error cell.', '08S01'),
                };
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Close without throwing or issuing speculative SQL from a destructor. */
    public function close(): void { $this->connection?->close(); $this->transaction = false; }
    public function __destruct() { $this->close(); }
}

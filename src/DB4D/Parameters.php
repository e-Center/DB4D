<?php
declare(strict_types=1);

namespace DB4D;

/** SQL lexer: substitute placeholders only outside literals, identifiers and comments. */
final class Parameters
{
    public static function render(string $sql, #[\SensitiveParameter] array $values, array $types = []): string
    {
        $out = ''; $used = []; $position = 0; $kind = null; $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i]; $next = $sql[$i + 1] ?? '';
            if ($char === "'" || $char === '"' || $char === '[') {
                $end = $char === '[' ? ']' : $char;
                $start = $i++;
                while ($i < $length) {
                    if ($sql[$i++] === $end) {
                        if (($sql[$i] ?? '') === $end) { $i++; continue; }
                        $out .= substr($sql, $start, $i - $start);
                        continue 2;
                    }
                }
                throw new DB4DException('Unterminated SQL literal or identifier.', '42000');
            }
            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end + 1;
                $out .= substr($sql, $i, $end - $i); $i = $end; continue;
            }
            if ($char === '/' && $next === '*') {
                $start = $i; $i += 2; $depth = 1;
                while ($i < $length && $depth > 0) {
                    $pair = substr($sql, $i, 2);
                    if ($pair === '/*') { $depth++; $i += 2; }
                    elseif ($pair === '*/') { $depth--; $i += 2; }
                    else { $i++; }
                }
                if ($depth) { throw new DB4DException('Unterminated SQL comment.', '42000'); }
                $out .= substr($sql, $start, $i - $start); continue;
            }
            // A single command prevents unconsumed multiple-result bodies on the wire.
            if ($char === ';') {
                if (trim(substr($sql, $i + 1)) !== '') {
                    throw new DB4DException('Multiple SQL statements are not supported.', 'IM001');
                }
                $i++; continue;
            }
            if ($char === '?' || ($char === ':' && $next !== ':' && ($sql[$i - 1] ?? '') !== ':' && preg_match('/[A-Za-z_]/', $next))) {
                $thisKind = $char === '?' ? 'positional' : 'named';
                if ($kind !== null && $kind !== $thisKind) { throw new DB4DException('Cannot mix named and positional parameters.', 'HY093'); }
                $kind = $thisKind;
                if ($char === '?') { $key = $position++; $i++; }
                else { preg_match('/\G:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $match, 0, $i); $key = $match[1]; $i += strlen($match[0]); }
                if (!array_key_exists($key, $values)) { throw new DB4DException('Missing SQL parameter.', 'HY093'); }
                $used[$key] = true;
                $out .= self::literal($values[$key], $types[$key] ?? null);
                continue;
            }
            $out .= $char; $i++;
        }
        if (count($used) !== count($values)) { throw new DB4DException('Unexpected SQL parameter.', 'HY093'); }
        return $out;
    }

    public static function normalize(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if ((is_string($key) && !preg_match('/^:?[A-Za-z_][A-Za-z0-9_]*$/D', $key)) || (is_int($key) && $key < 0)) {
                throw new DB4DException('Invalid SQL parameter key.', 'HY093');
            }
            $key = is_string($key) ? ltrim($key, ':') : $key;
            if (array_key_exists($key, $result)) { throw new DB4DException('Duplicate SQL parameter.', 'HY093'); }
            $result[$key] = $value;
        }
        return $result;
    }

    public static function literal(#[\SensitiveParameter] mixed $value, ?int $type = null): string
    {
        if ($type !== null && !in_array($type, [\PDO::PARAM_NULL, \PDO::PARAM_BOOL, \PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_LOB], true)) {
            throw new DB4DException('Unsupported parameter type.', 'IM001');
        }
        if ($type === \PDO::PARAM_NULL || $value === null) { return 'NULL'; }
        if ($type === \PDO::PARAM_LOB) {
            if (is_resource($value) && get_resource_type($value) === 'stream') { $value = stream_get_contents($value); }
            if (!is_string($value)) { throw new DB4DException('A LOB parameter must be bytes or a readable stream.', 'HY105'); }
            return "X'" . bin2hex($value) . "'";
        }
        if ($type === \PDO::PARAM_INT) {
            if (!is_int($value) && (!is_string($value) || !preg_match('/^[+-]?[0-9]+$/D', $value))) {
                throw new DB4DException('Invalid integer parameter.', '22018');
            }
            return (string) $value;
        }
        if ($type === \PDO::PARAM_BOOL || ($type === null && is_bool($value))) {
            return 'CAST(' . ((bool) $value ? '1' : '0') . ' AS BOOLEAN)';
        }
        if ($type !== null && $type !== \PDO::PARAM_STR) { throw new DB4DException('Unsupported parameter type.', 'IM001'); }
        if ($type === null && (is_int($value) || is_float($value))) {
            if (is_float($value) && !is_finite($value)) { throw new DB4DException('Non-finite numeric parameter.', '22003'); }
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (!is_scalar($value) || !mb_check_encoding((string) $value, 'UTF-8') || str_contains((string) $value, "\0")) {
            throw new DB4DException('Text parameters must be UTF-8 without NUL; use PARAM_LOB for bytes.', '22021');
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}

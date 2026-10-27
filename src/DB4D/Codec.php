<?php
declare(strict_types=1);

namespace DB4D;

/** Wire types verified against lib4d_sql in the PDO_4D 0.3 sources. */
final class Codec
{
    public static function signed32(string $bytes): int
    {
        $unsigned = unpack('V', $bytes)[1];
        return $unsigned >= 2147483648 ? $unsigned - 4294967296 : $unsigned;
    }

    public static function signed64(string $bytes): int
    {
        // q is signed; reverse only on big-endian hosts. Avoid floating-point arithmetic.
        return unpack('q', pack('S', 1) === "\x01\x00" ? $bytes : strrev($bytes))[1];
    }

    public static function value(string $type, callable $read, int $maximum, string $dateFormat): mixed
    {
        return match ($type) {
            'VK_BOOLEAN' => unpack('v', $read(2))[1] !== 0,
            'VK_BYTE', 'VK_WORD' => self::short($read(2)),
            'VK_LONG' => self::signed32($read(4)),
            'VK_LONG8', 'VK_DURATION' => self::signed64($read(8)),
            'VK_REAL' => unpack('e', $read(8))[1],
            'VK_STRING', 'VK_TEXT' => self::text($read, $maximum),
            'VK_BLOB', 'VK_IMAGE' => $read(self::length(self::signed32($read(4)), $maximum)),
            'VK_TIMESTAMP', 'VK_TIME' => self::timestamp($read(8), $dateFormat),
            // VK_FLOAT is 4D's proprietary numeric format, not IEEE-754 single precision.
            default => throw new DB4DException('Unsupported 4D wire type: ' . $type . '.', 'IM001'),
        };
    }

    private static function short(string $bytes): int
    {
        $n = unpack('v', $bytes)[1];
        return $n >= 32768 ? $n - 65536 : $n;
    }

    private static function length(int $n, int $maximum): int
    {
        if ($n < 0 || $n > $maximum) { throw new DB4DException('4D field exceeds its configured size limit.', '08S01'); }
        return $n;
    }

    private static function text(callable $read, int $maximum): string
    {
        $units = -self::signed32($read(4));
        $bytes = $read(self::length($units * 2, $maximum));
        if (!mb_check_encoding($bytes, 'UTF-16LE')) { throw new DB4DException('Invalid UTF-16 returned by 4D.', '22021'); }
        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }

    private static function timestamp(string $bytes, string $format): string
    {
        $t = unpack('vyear/Cmonth/Cday/Vmillis', $bytes);
        $m = $t['millis'];
        if ($m >= 86400000 || (!checkdate($t['month'], $t['day'], $t['year']) && ($t['year'] || $t['month'] || $t['day']))) {
            throw new DB4DException('Invalid 4D timestamp.', '22007');
        }
        $h = intdiv($m, 3600000); $min = intdiv($m % 3600000, 60000); $s = intdiv($m % 60000, 1000);
        if ($format === 'legacy') {
            $date = sprintf('%02d.%02d.%04d', $t['day'], $t['month'], $t['year']);
            return $m === 0 ? $date : $date . sprintf(' %02d:%02d:%02d', $h, $min, $s);
        }
        // Match the textual date contract of PDO_4D, including milliseconds.
        return sprintf('%04d/%02d/%02d %02d:%02d:%02d.%03d', $t['year'], $t['month'], $t['day'], $h, $min, $s, $m % 1000);
    }
}

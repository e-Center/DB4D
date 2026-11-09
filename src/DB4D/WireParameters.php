<?php
declare(strict_types=1);
namespace DB4D;

/** Bounded SQL 4D parameter frames; lengths are byte lengths, except UTF-16 code units. */
final class WireParameters
{
    public static function encode(mixed $value, ?int $type, int $maximum): array
    {
        if ($type !== null && !in_array($type,[\PDO::PARAM_NULL,\PDO::PARAM_BOOL,\PDO::PARAM_INT,\PDO::PARAM_STR,\PDO::PARAM_LOB],true)) {
            throw new DB4DException('Unsupported parameter type.','IM001');
        }
        if ($type===\PDO::PARAM_NULL || $value===null) { return ['VK_STRING','0']; }
        if ($type===\PDO::PARAM_LOB) {
            if (is_resource($value) && get_resource_type($value)==='stream') { $value=stream_get_contents($value,$maximum+1); }
            if (!is_string($value)) { throw new DB4DException('LOB parameters require bytes or a readable stream.','HY105'); }
            self::bounded(strlen($value),$maximum);
            return ['VK_BLOB','1'.pack('V',strlen($value)).$value];
        }
        if ($type===\PDO::PARAM_BOOL || ($type===null && is_bool($value))) { return ['VK_BOOLEAN','1'.pack('v',(bool)$value?1:0)]; }
        if ($type===\PDO::PARAM_INT || ($type===null && is_int($value))) {
            if (!is_int($value)) {
                if(!is_string($value) || !preg_match('/^[+-]?[0-9]+$/D',$value)){throw new DB4DException('Invalid integer parameter.','22018');}
                $negative=str_starts_with($value,'-');$digits=ltrim(ltrim($value,'+-'),'0');$digits=$digits===''?'0':$digits;
                $bound=$negative?'9223372036854775808':'9223372036854775807';
                if(strlen($digits)>strlen($bound) || (strlen($digits)===strlen($bound) && strcmp($digits,$bound)>0)){throw new DB4DException('Integer parameter overflow.','22003');}
                $value=(int)(($negative?'-':'').$digits);
            }
            return $value>=-2147483648 && $value<=2147483647 ? ['VK_LONG','1'.pack('V',$value)] : ['VK_LONG8','1'.self::int64($value)];
        }
        if ($type===null && is_float($value)) {
            if(!is_finite($value)){throw new DB4DException('Non-finite parameter.','22003');}
            return ['VK_REAL','1'.pack('e',$value)];
        }
        if(!is_scalar($value) || !mb_check_encoding((string)$value,'UTF-8')){throw new DB4DException('Text parameters must be UTF-8.','22021');}
        self::bounded(strlen((string)$value),$maximum);
        $utf16=mb_convert_encoding((string)$value,'UTF-16LE','UTF-8');self::bounded(strlen($utf16),$maximum);
        return ['VK_STRING','1'.pack('V',-intdiv(strlen($utf16),2)).$utf16];
    }
    private static function int64(int $n): string { $b=pack('q',$n);return pack('S',1)==="\x01\x00"?$b:strrev($b); }
    private static function bounded(int $length,int $maximum): void
    {
        if($length>$maximum || $length>2147483647){throw new DB4DException('Parameter exceeds its byte limit.','HY001');}
    }
}

<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;

/** Decode trusted PostgreSQL JSONB without rounding historical arbitrary-precision
 * decimals. Only numeric literals outside strings are transformed. New events
 * already follow the same precision policy at ingest. */
final class StoredLogJson
{
    public const DETAIL_BYTES=4194304;

    public static function decode(string $json): mixed
    {
        if (strlen($json)>self::DETAIL_BYTES) self::tooLarge();
        $length=strlen($json); $i=0; $start=0; $parts=[];
        while ($i<$length) {
            // JSONB output is valid JSON, but the final decoder still validates it.
            $i+=strcspn($json,'"-0123456789',$i);
            if ($i===$length) break;
            if ($json[$i]==='"') {
                ++$i;
                while ($i<$length) {
                    $i+=strcspn($json,"\"\\",$i);
                    if ($i===$length) break;
                    if ($json[$i++]==='"') break;
                    ++$i; // skip escaped byte (including an escaped quote)
                }
                continue;
            }
            if (!preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',$json,$m,0,$i)) {
                throw new \JsonException('Invalid stored JSON.');
            }
            $number=$m[0]; $end=$i+strlen($number);
            if (strpbrk($number,'.eE')!==false) {
                $mantissa=preg_split('/[eE]/',$number,2)[0];
                $digits=trim(str_replace(['-','.'],'',$mantissa),'0');
                $value=(float)$number;
                if (strlen($digits)>15 || !is_finite($value) || ($value==0.0 && $digits!=='')) {
                    $parts[]=substr($json,$start,$i-$start);
                    $parts[]='"'.$number.'"'; $start=$end;
                }
            }
            $i=$end;
        }
        if ($parts!==[]) { $parts[]=substr($json,$start); $json=implode('',$parts); }
        return json_decode($json,false,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
    }

    public static function tooLarge(): never
    {
        throw new HttpException(413,'payload_too_large','Stored log event exceeds the detail byte budget.');
    }
}

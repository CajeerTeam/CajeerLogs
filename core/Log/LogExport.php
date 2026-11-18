<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

final class LogExport
{
    private const COLUMNS=['id','project_id','source_id','timestamp','received_at','source','service','host','level','message','trace_id','request_id','context','tags'];
    /** Same output encoding for single-page and streaming exports. */
    public static function csvHeader($stream): void { self::csvWrite($stream,self::COLUMNS); }
    public static function csvRow($stream,array $item): void
    {
        $cells=[];
        foreach (self::COLUMNS as $key) {
            $value=$item[$key]??'';
            if (!is_scalar($value)) { $value=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
            $value=(string)$value;
            if (preg_match('/^(?:[\s\x00-\x1f\x7f]*[=+\-@＝＋－＠]|[\t\r\n])/u',$value)) { $value="'".$value; }
            $cells[]=$value;
        }
        self::csvWrite($stream,$cells);
    }
    private static function csvWrite($stream,array $cells): void
    {
        if (fputcsv($stream,$cells,',','"','',"\r\n")===false) { throw new \RuntimeException('CSV write failed.'); }
    }
    public static function csv(array $items): string
    {
        $stream=fopen('php://temp','w+b');
        if ($stream===false) { throw new \RuntimeException('Cannot create CSV stream.'); }
        try {
            self::csvHeader($stream);
            foreach ($items as $item) self::csvRow($stream,$item);
            rewind($stream); $csv=stream_get_contents($stream);
            if ($csv===false) { throw new \RuntimeException('CSV read failed.'); }
            return $csv;
        } finally { fclose($stream); }
    }
}

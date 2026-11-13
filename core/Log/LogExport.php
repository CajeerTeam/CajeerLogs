<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

final class LogExport
{
    private const COLUMNS=['id','project_id','source_id','timestamp','received_at','source','service','host','level','message','trace_id','request_id','context','tags'];
    /** CSV is bounded to the same page as JSON; neutralize spreadsheet formula injection. */
    public static function csv(array $items): string
    {
        $stream=fopen('php://temp','w+b');
        if ($stream===false) { throw new \RuntimeException('Cannot create CSV stream.'); }
        try {
            if (fputcsv($stream,self::COLUMNS,',','"','',"\r\n")===false) { throw new \RuntimeException('CSV write failed.'); }
            foreach ($items as $item) {
                $cells=[];
                foreach (self::COLUMNS as $key) {
                    $value=$item[$key]??'';
                    if (!is_scalar($value)) { $value=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
                    $value=(string)$value;
                    if (preg_match('/^(?:[\s\x00-\x1f\x7f]*[=+\-@＝＋－＠]|[\t\r\n])/u',$value)) { $value="'".$value; }
                    $cells[]=$value;
                }
                if (fputcsv($stream,$cells,',','"','',"\r\n")===false) { throw new \RuntimeException('CSV write failed.'); }
            }
            rewind($stream); $csv=stream_get_contents($stream);
            if ($csv===false) { throw new \RuntimeException('CSV read failed.'); }
            return $csv;
        } finally { fclose($stream); }
    }
}

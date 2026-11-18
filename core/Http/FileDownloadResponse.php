<?php
declare(strict_types=1);
namespace Cajeer\Logs\Http;

/** Stream a verified immutable private artifact without loading it into PHP memory. */
final class FileDownloadResponse extends Response
{
    public function __construct(private readonly string $file,string $name,string $format,int $bytes)
    {
        parent::__construct('',200,[
            'Content-Type'=>$format==='csv'?'text/csv; charset=utf-8':'application/json; charset=utf-8',
            'Content-Length'=>(string)$bytes,
            'Content-Disposition'=>'attachment; filename="'.$name.'"',
            'Cache-Control'=>'private, no-store',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
    public function send(bool $head=false): void
    {
        // Open before emitting response headers; never follow symlinked artifacts.
        if (is_link($this->file) || !is_file($this->file)) throw new HttpException(410,'export_missing','Export file is unavailable.');
        $stream=fopen($this->file,'rb');
        if ($stream===false) throw new HttpException(410,'export_missing','Export file is unavailable.');
        try {
            parent::send(true);
            if (!$head) {
                while (!feof($stream)) {
                    $chunk=fread($stream,65536);
                    if ($chunk===false) { error_log('CajeerLogs export stream read failed'); break; }
                    if ($chunk==='') break;
                    echo $chunk;
                }
            }
        } finally { fclose($stream); }
    }
}

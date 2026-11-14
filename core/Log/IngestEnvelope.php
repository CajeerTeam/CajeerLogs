<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\{Request,HttpException};

/** Validate HTTP framing before any database work. The web server owns wire decoding. */
final class IngestEnvelope
{
    public static function validate(Request $request,int $limit): void
    {
        $length=$request->header('Content-Length');
        $transfer=$request->header('Transfer-Encoding');
        if ($length!==null && $transfer!==null) throw new HttpException(400,'ambiguous_body','Conflicting body framing.');
        if ($transfer!==null && strtolower(trim($transfer))!=='chunked') throw new HttpException(400,'invalid_transfer_encoding','Unsupported transfer framing.');
        if ($length!==null) {
            if (!preg_match('/^[0-9]+$/D',$length)) throw new HttpException(400,'invalid_content_length','Invalid Content-Length.');
            if ((float)$length>$limit) throw new HttpException(413,'payload_too_large','Ingest body exceeds configured limit.');
            if ((int)$length!==strlen($request->body())) throw new HttpException(400,'invalid_body','Content-Length does not match body.');
        }
        if (strlen($request->body())>$limit) throw new HttpException(413,'payload_too_large','Ingest body exceeds configured limit.');
        $type=trim($request->header('Content-Type','')??'');
        // JSON and structured JSON media types, with optional UTF-8 charset only.
        if (!preg_match('~^(?:application/json|[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+\+json)(?:[ \t]*;[ \t]*charset[ \t]*=[ \t]*(?:UTF-8|"UTF-8"))?$~iD',$type)) {
            throw new HttpException(415,'unsupported_media_type','Expected JSON encoded as UTF-8.');
        }
        if (!in_array(strtolower(trim($request->header('Content-Encoding','identity')??'identity')),['','identity'],true)) throw new HttpException(415,'unsupported_media_type','Compressed request bodies are not supported.');
        if ($request->body()==='') throw new HttpException(400,'invalid_json','Empty JSON request body.');
    }
}

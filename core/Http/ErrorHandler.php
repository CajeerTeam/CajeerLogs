<?php
declare(strict_types=1);

namespace Cajeer\Logs\Http;

final class ErrorHandler
{
    public static function response(\Throwable $error, string $requestId): Response
    {
        if ($error instanceof HttpException && $error->status < 500) {
            return new JsonResponse(['error' => $error->error, 'message' => $error->getMessage(), 'request_id' => $requestId], $error->status, $error->headers);
        }
        // Do not log request bodies, headers, exception messages or traces containing credentials.
        error_log(sprintf('CajeerLogs request_id=%s exception=%s at %s:%d', $requestId, $error::class, basename($error->getFile()), $error->getLine()));
        $status = $error instanceof HttpException ? $error->status : (\Cajeer\Logs\Database\DatabaseFailure::unavailable($error) ? 503 : 500);
        if ($status === 503 && $error instanceof HttpException && $error->error === 'queue_full') {
            return new JsonResponse(['error'=>'queue_full','message'=>'Ingest queue capacity reached.','request_id'=>$requestId],503,['Retry-After'=>'5']);
        }
        if ($status === 503 && $error instanceof HttpException && $error->error === 'ingest_busy') { return new JsonResponse(['error'=>'ingest_busy','message'=>'Ingest capacity reached.','request_id'=>$requestId],503,['Retry-After'=>'1']); }
        if ($status === 503 && $error instanceof HttpException && $error->error === 'database_unavailable') { return new JsonResponse(['error'=>'service_unavailable','message'=>'Service temporarily unavailable.','request_id'=>$requestId],503,['Retry-After'=>'5']); }
        if ($status === 503) { return new JsonResponse(['error'=>'service_unavailable','message'=>'Service temporarily unavailable.','request_id'=>$requestId],503); }
        return new JsonResponse(['error' => 'internal_error', 'message' => 'Internal server error.', 'request_id' => $requestId], $status);
    }

    public static function decorate(Response $response, string $requestId, bool $secure = false): Response
    {
        if ($secure) { $response=$response->withHeader('Strict-Transport-Security','max-age=31536000'); }
        return $response->withHeader('X-Request-ID', $requestId)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Security-Policy', $response->header('Content-Security-Policy') ?? "default-src 'none'; frame-ancestors 'none'");
    }
}

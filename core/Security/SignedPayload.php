<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Http\{HttpException,Request};

final class SignedPayload
{
    public function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function canonical(Request $r, string $timestamp, string $nonce, ?string $key): string
    {
        return implode("\n", ['cajeerlogs-ingest-v1',$r->method,$r->path,$timestamp,$nonce,$key??'',hash('sha256',$r->body())]);
    }

    /** Returns the authenticated nonce, or null if optional signing was not requested. */
    public function verify(Request $r, string $secret, ?string $key, bool $required, ?int $now = null): ?string
    {
        $timestamp=$r->header('X-Log-Timestamp'); $nonce=$r->header('X-Log-Nonce'); $signature=$r->header('X-Log-Signature');
        if (!$required && $timestamp===null && $nonce===null && $signature===null) { return null; }
        if ($timestamp===null || $nonce===null || $signature===null || !preg_match('/^[1-9][0-9]{9}$/D',$timestamp)
            || !preg_match('/^[a-f0-9]{32,64}$/D',$nonce) || !preg_match('/^[a-f0-9]{64}$/D',$signature)
            || abs(($now??time())-(int)$timestamp)>300
            || !hash_equals($this->sign(self::canonical($r,$timestamp,$nonce,$key),$secret),$signature)) {
            throw new HttpException(401,'invalid_signature','Missing or invalid ingest signature.', ['WWW-Authenticate'=>'Bearer realm="CajeerLogs"']);
        }
        return $nonce;
    }
}

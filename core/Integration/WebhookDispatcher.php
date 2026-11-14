<?php
declare(strict_types=1);
namespace Cajeer\Logs\Integration;

/** HTTPS-only, no redirects or proxies; public IPv4 DNS results pinned to the connection. */
final class WebhookDispatcher
{
    public static function host(string $url): string
    {
        $u=parse_url($url);
        if (!$u || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL) || ($u['scheme']??'')!=='https' || isset($u['user']) || isset($u['pass']) || isset($u['fragment']) || isset($u['query']) || ($u['port']??443)!==443 || !preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/D',$u['host']??'')) {
            throw new \InvalidArgumentException('Webhook must be an HTTPS DNS hostname on port 443 without credentials, query or fragment.');
        }
        return strtolower($u['host']);
    }
    public static function publicIp(string $ip): bool
    {
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_GLOBAL_RANGE)!==false
            && (int)explode('.', $ip)[0] < 224;
    }
    public static function signature(string $id,string $timestamp,string $body,string $secret): string
    {
        return hash_hmac('sha256',implode("\n",['cajeerlogs-webhook-v1',$id,$timestamp,hash('sha256',$body)]),$secret);
    }
    /** Return HTTP status, null on transport error; never expose response or secret. */
    public function dispatch(string $url,string $id,string $body,string $secret): ?int
    {
        $host=self::host($url);
        if (strlen($secret)<32 || !extension_loaded('curl')) { throw new \RuntimeException('Webhook requires ext-curl and a signing secret of at least 32 bytes.'); }
        $ips=gethostbynamel($host);
        if (!$ips || !array_all($ips,self::publicIp(...))) { throw new \RuntimeException('Webhook DNS must resolve exclusively to public IPv4 addresses.'); }
        $ts=(string)time();
        $ch=curl_init($url);
        if ($ch===false) { return null; }
        try {
            if (!curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Cajeer-Delivery: '.$id,'X-Cajeer-Timestamp: '.$ts,'X-Cajeer-Signature: '.self::signature($id,$ts,$body,$secret)],
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS_STR=>'https',CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static fn($handle,string $chunk):int=>strlen($chunk)])) { throw new \RuntimeException('Cannot configure secure webhook transport.'); }
            if (curl_exec($ch)===false) { return null; }
            return (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        } finally { unset($ch); }
    }
}

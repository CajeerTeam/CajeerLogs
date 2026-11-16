<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

/** Explicit operator probe: verifies the configured public endpoint, never disables TLS validation. */
final class TlsProbe
{
    public static function check(string $url, ?string $ca=null, int $warningDays=14): array
    {
        $parts=parse_url($url);
        if (($parts['scheme']??null)!=='https' || !isset($parts['host']) || $warningDays<0 || $warningDays>365) throw new \InvalidArgumentException('TLS probe requires HTTPS APP_URL and warning days 0..365.');
        if ($ca!==null && (!is_file($ca)||!is_readable($ca))) throw new \InvalidArgumentException('TLS CA file is not readable.');
        $host=trim($parts['host'],'[]'); $port=$parts['port']??443;
        $ssl=['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'capture_peer_cert'=>true,'SNI_enabled'=>true,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT];
        if ($ca!==null) $ssl['cafile']=$ca;
        $context=stream_context_create(['ssl'=>$ssl]);
        $address=str_contains($host,':')?'['.$host.']':$host;
        $socket=@stream_socket_client('tls://'.$address.':'.$port,$errno,$errstr,5,STREAM_CLIENT_CONNECT,$context);
        if ($socket===false) return ['status'=>'failed','reason'=>'tls_connection_or_verification_failed'];
        try {
            $cert=stream_context_get_params($socket)['options']['ssl']['peer_certificate']??null;
            $parsed=$cert!==null?openssl_x509_parse($cert):false;
            if (!$parsed || !isset($parsed['validTo_time_t'],$parsed['validFrom_time_t'])) return ['status'=>'failed','reason'=>'certificate_unavailable'];
            $remaining=$parsed['validTo_time_t']-time();
            $crypto=stream_get_meta_data($socket)['crypto']??[];
            return ['status'=>$remaining<=$warningDays*86400?'expiring':'ok','host'=>$host,'port'=>$port,'expires_at'=>gmdate('c',$parsed['validTo_time_t']),'remaining_seconds'=>$remaining,'protocol'=>$crypto['protocol']??null,'sha256_fingerprint'=>openssl_x509_fingerprint($cert,'sha256')];
        } finally { fclose($socket); }
    }
}

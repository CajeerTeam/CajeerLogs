<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Http\HttpException;

/** Trust only the transport peer. A configured last-hop proxy must overwrite both headers. */
final class DeploymentSecurity
{
    private array $networks=[];
    public function __construct(private readonly ConfigRepository $config)
    {
        $raw=$config->get('security.trusted_proxies','');
        if (!is_string($raw) || strlen($raw)>4096) throw new \InvalidArgumentException('Invalid SECURITY_TRUSTED_PROXIES.');
        if ($raw!=='') foreach (explode(',',$raw) as $entry) {
            $parts=explode('/',trim($entry));
            $ip=filter_var($parts[0],FILTER_VALIDATE_IP)!==false?inet_pton($parts[0]):false;
            if ($ip===false || count($parts)>2) throw new \InvalidArgumentException('Invalid trusted proxy address.');
            if (strlen($ip)===16 && substr($ip,0,12)===str_repeat("\0",10)."\xff\xff") throw new \InvalidArgumentException('Configure mapped proxy addresses as IPv4.');
            $bits=strlen($ip)*8;
            $prefix=$parts[1]??(string)$bits;
            if (!ctype_digit($prefix) || (int)$prefix<1 || (int)$prefix>$bits) throw new \InvalidArgumentException('Invalid trusted proxy prefix (universal trust is forbidden).');
            $this->networks[]=[$ip,(int)$prefix];
        }
        if (count($this->networks)>64) throw new \InvalidArgumentException('Too many trusted proxies.');
        if (!is_bool($config->get('database.require_tls',false))) throw new \InvalidArgumentException('Invalid DB_REQUIRE_TLS.');
        if ($config->get('database.require_tls',false) && ($config->get('database.sslmode')!=='verify-full' || !is_string($config->get('database.sslrootcert')) || !is_file($config->get('database.sslrootcert')) || !is_readable($config->get('database.sslrootcert')))) throw new \InvalidArgumentException('DB_REQUIRE_TLS requires verify-full and a readable DB_SSLROOTCERT.');
    }
    private static function address(string $value): ?string
    {
        if (filter_var($value,FILTER_VALIDATE_IP)===false) return null;
        $packed=inet_pton($value);
        if (strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") $packed=substr($packed,12);
        return inet_ntop($packed);
    }
    public function trusted(string $peer): bool
    {
        $canonical=self::address($peer);
        if ($canonical===null) return false;
        $ip=inet_pton($canonical);
        foreach ($this->networks as [$network,$prefix]) {
            if (strlen($network)!==strlen($ip)) continue;
            $bytes=intdiv($prefix,8); $bits=$prefix%8;
            if (substr($network,0,$bytes)!==substr($ip,0,$bytes)) continue;
            if ($bits===0 || ((ord($network[$bytes])^ord($ip[$bytes])) & (255 << (8-$bits)))===0) return true;
        }
        return false;
    }
    /** @return array{address:string,secure:bool} */
    public function transport(string $peer,bool $tls,array $headers): array
    {
        $address=self::address($peer)??'unknown';
        if (!$this->trusted($peer)) return ['address'=>$address,'secure'=>$tls];
        // No comma-separated chains, ports, quoted values, or alternative Forwarded syntax.
        $client=self::address($headers['X-FORWARDED-FOR']??'');
        $proto=$headers['X-FORWARDED-PROTO']??'';
        if ($client===null || !in_array($proto,['http','https'],true)) throw new HttpException(400,'invalid_proxy_headers','Trusted proxy must supply a single client address and scheme.');
        return ['address'=>$client,'secure'=>$proto==='https'];
    }
    public function assertRequest(string $method,string $path,?string $host,bool $secure): void
    {
        if ($this->config->get('app.env')!=='production') return;
        $probe=in_array($method,['GET','HEAD'],true) && in_array($path,['/','/health','/ready',$this->config->get('api.prefix').'/health'],true);
        if (!$probe && (!$secure || parse_url($this->config->get('app.url'),PHP_URL_SCHEME)!=='https')) throw new HttpException(403,'https_required','HTTPS is required.');
        // Plain internal probes remain usable; no credentials or protected data are served by them.
        if ($probe && !$secure) return;
        $url=parse_url($this->config->get('app.url'));
        $expected=strtolower($url['host']); $port=$url['port']??443;
        $allowed=[$expected.':'.$port]; if ($port===443) $allowed[]=$expected;
        if ($host===null || !in_array(strtolower($host),$allowed,true)) throw new HttpException(400,'invalid_host','Request host does not match the configured public origin.');
    }
    public function snapshot(): array
    {
        return ['https_configured'=>parse_url($this->config->get('app.url'),PHP_URL_SCHEME)==='https','trusted_proxy_count'=>count($this->networks),'production_host_validation'=>$this->config->get('app.env')==='production','database_tls_required'=>$this->config->get('database.require_tls',false),'database_sslmode'=>$this->config->get('database.sslmode')];
    }
}

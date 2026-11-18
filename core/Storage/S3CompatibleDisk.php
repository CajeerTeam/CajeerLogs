<?php
declare(strict_types=1);

namespace Cajeer\Logs\Storage;

/** Bounded S3-compatible object transport using AWS Signature Version 4. No SDK dependency. */
final class S3CompatibleDisk
{
    private string $endpoint;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $region;
    private bool $pathStyle;
    private string $caFile;

    public function __construct(?array $config=null)
    {
        $config ??= [
            'endpoint'=>getenv('S3_ENDPOINT') ?: '', 'bucket'=>getenv('S3_BUCKET') ?: '',
            'access_key'=>getenv('S3_ACCESS_KEY') ?: '', 'secret_key'=>getenv('S3_SECRET_KEY') ?: '',
            'region'=>getenv('S3_REGION') ?: 'us-east-1',
            'path_style'=>!in_array(strtolower(getenv('S3_PATH_STYLE') ?: 'true'),['false','0'],true),
            'allow_insecure'=>getenv('S3_ALLOW_INSECURE')==='true',
            'ca_file'=>getenv('S3_TLS_CA_FILE') ?: '',
        ];
        $url=(string)($config['endpoint']??''); $parts=parse_url($url);
        if ($parts===false || !is_array($parts) || !in_array($parts['scheme']??'', ['https','http'],true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || !in_array($parts['path']??'', ['', '/'],true)
            || !filter_var($url,FILTER_VALIDATE_URL)) throw new \InvalidArgumentException('S3 endpoint must be an HTTP(S) origin.');
        if ($parts['scheme']!=='https' && ($config['allow_insecure']??false)!==true) {
            throw new \InvalidArgumentException('S3 plaintext transport requires explicit S3_ALLOW_INSECURE=true.');
        }
        $this->bucket=(string)($config['bucket']??'');
        $this->accessKey=(string)($config['access_key']??''); $this->secretKey=(string)($config['secret_key']??'');
        $this->region=(string)($config['region']??'');
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D',$this->bucket)
            || str_contains($this->bucket,'..') || !preg_match('/^[a-zA-Z0-9_+\/=-]{3,128}$/D',$this->accessKey)
            || strlen($this->secretKey)<8 || strlen($this->secretKey)>256 || preg_match('/[\r\n]/',$this->secretKey)
            || !preg_match('/^[a-z0-9-]{1,32}$/D',$this->region)) {
            throw new \InvalidArgumentException('Invalid S3 bucket, region or credentials.');
        }
        $this->caFile=(string)($config['ca_file']??'');
        if ($this->caFile!=='' && (is_link($this->caFile) || !is_file($this->caFile) || !is_readable($this->caFile))) {
            throw new \InvalidArgumentException('Invalid S3 TLS CA file.');
        }
        $this->pathStyle=(bool)($config['path_style']??true);
        $this->endpoint=rtrim($url,'/');
    }

    public function configured(): bool { return $this->endpoint!=='' && $this->bucket!==''; }

    /** Upload a bounded immutable object; returns the digest verified by an independent GET. */
    public function putVerified(string $key,string $body): array
    {
        if ($body==='' || strlen($body)>16777216) throw new \InvalidArgumentException('S3 payload must be 1..16 MiB.');
        $digest=hash('sha256',$body);
        $this->request('PUT',$key,$body,$digest);
        $roundTrip=$this->getVerified($key,$digest);
        if (!hash_equals($body,$roundTrip)) throw new \RuntimeException('S3 readback differs from uploaded payload.');
        return ['key'=>$key,'sha256'=>$digest,'bytes'=>strlen($body)];
    }

    public function getVerified(string $key,string $sha256): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha256)) throw new \InvalidArgumentException('Invalid S3 expected digest.');
        $body=$this->request('GET',$key);
        if (!hash_equals($sha256,hash('sha256',$body))) throw new \RuntimeException('S3 object checksum mismatch.');
        return $body;
    }

    public function delete(string $key): void { $this->request('DELETE',$key); }

    private static function key(string $key): string
    {
        if ($key==='' || strlen($key)>512 || !preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_.-]+)*$~D',$key)
            || str_contains($key,'..')) throw new \InvalidArgumentException('Unsafe S3 object key.');
        return $key;
    }

    private function request(string $method,string $key,string $body='',?string $metaSha=null): string
    {
        $key=self::key($key);
        $parts=parse_url($this->endpoint); $host=(string)$parts['host'];
        if (!$this->pathStyle) $host=$this->bucket.'.'.$host;
        $port=isset($parts['port'])?':'.$parts['port']:'';
        $authority=$host.$port;
        $path='/'.($this->pathStyle?$this->bucket.'/':'').implode('/',array_map('rawurlencode',explode('/',$key)));
        $uri=$parts['scheme'].'://'.$authority.$path;
        $now=gmdate('Ymd\THis\Z'); $day=substr($now,0,8); $payloadSha=hash('sha256',$body);
        $headers=['host'=>$authority,'x-amz-content-sha256'=>$payloadSha,'x-amz-date'=>$now];
        if ($metaSha!==null) $headers['x-amz-meta-sha256']=$metaSha;
        ksort($headers,SORT_STRING);
        $canonicalHeaders='';foreach ($headers as $name=>$value) $canonicalHeaders.=$name.':'.$value."\n";
        $signedHeaders=implode(';',array_keys($headers));
        $canonical=$method."\n".$path."\n\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadSha;
        $scope=$day.'/'.$this->region.'/s3/aws4_request';
        $toSign='AWS4-HMAC-SHA256' . "\n" . $now . "\n" . $scope . "\n" . hash('sha256',$canonical);
        $keyDate=hash_hmac('sha256',$day,'AWS4'.$this->secretKey,true);
        $keyRegion=hash_hmac('sha256',$this->region,$keyDate,true);
        $keyService=hash_hmac('sha256','s3',$keyRegion,true);
        $signingKey=hash_hmac('sha256','aws4_request',$keyService,true);
        $signature=hash_hmac('sha256',$toSign,$signingKey);
        $headers['authorization']='AWS4-HMAC-SHA256 Credential='.$this->accessKey.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;
        $lines=[];foreach ($headers as $name=>$value) $lines[]=$name.': '.$value;
        $lines[]='Connection: close';
        if ($method==='PUT') $lines[]='Content-Type: application/octet-stream';
        $context=stream_context_create([
            'http'=>['method'=>$method,'header'=>implode("\r\n",$lines)."\r\n",'content'=>$body,'timeout'=>15,
                'ignore_errors'=>true,'follow_location'=>0,'protocol_version'=>1.1],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]+($this->caFile!==''?['cafile'=>$this->caFile]:[]),
        ]);
        $stream=@fopen($uri,'rb',false,$context);
        if ($stream===false) throw new \RuntimeException('S3 request failed (connection/TLS).');
        try {
            $metadata=stream_get_meta_data($stream);
            $responseHeaders=$metadata['wrapper_data']??[];
            $statusLine=$responseHeaders[0]??'';
            if (!preg_match('~^HTTP/\d(?:\.\d)?\s+(\d{3})\b~',$statusLine,$m)) throw new \RuntimeException('Invalid S3 HTTP response.');
            $status=(int)$m[1];
            if (!in_array($status,($method==='PUT'?[200,201]:($method==='DELETE'?[200,202,204]:[200])),true)) {
                throw new \RuntimeException('S3 operation returned HTTP '.$status.'.');
            }
            $response='';
            while (!feof($stream)) {
                $chunk=fread($stream,65536);
                if ($chunk===false) throw new \RuntimeException('S3 response interrupted.');
                if ($chunk==='') break;
                $response.=$chunk;
                if (strlen($response)>16777216) throw new \RuntimeException('S3 response exceeds 16 MiB.');
            }
            if (!feof($stream)) throw new \RuntimeException('S3 response truncated.');
            return $response;
        } finally { fclose($stream); }
    }
}

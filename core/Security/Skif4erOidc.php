<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

/** Confidential OIDC relying party. The issuer is pinned; discovery never accepts foreign endpoints. */
final class Skif4erOidc
{
    public const ISSUER='https://id.skif4er.ru/realms/skif4er';
    public function __construct(private readonly string $clientId, private readonly string $clientSecret, private readonly string $redirect, private readonly ?\Closure $transport=null) {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$clientId)||$clientSecret===''||strlen($clientSecret)>1024||preg_match('/[\x00-\x1f\x7f]/',$clientSecret)||!str_starts_with($redirect,'https://')) throw new \InvalidArgumentException('Invalid SkiF4er ID client configuration.');
    }
    public static function b64(string $s): string { return rtrim(strtr(base64_encode($s),'+/','-_'),'='); }
    private static function decode(string $s): string {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$s)||strlen($s)%4===1) throw new \RuntimeException('Invalid OIDC encoding.');
        $v=base64_decode(strtr($s,'-_','+/'),true);
        if ($v===false||self::b64($v)!==$s) throw new \RuntimeException('Invalid OIDC encoding.');
        return $v;
    }
    public static function random(): string { return self::b64(random_bytes(32)); }
    public static function challenge(string $verifier): string { return self::b64(hash('sha256',$verifier,true)); }
    private static function endpoint(string $url): string {
        $p=parse_url($url);
        if (!$p||($p['scheme']??'')!=='https'||($p['host']??'')!=='id.skif4er.ru'||($p['port']??443)!==443||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!str_starts_with($p['path']??'','/realms/skif4er/')||preg_match('~[%\\\\\x00-\x20]|(?:^|/)\.\.?(?:/|$)~',$p['path']??'')) throw new \RuntimeException('Untrusted OIDC endpoint.');
        return $url;
    }
    private function json(string $url,?array $form=null,?string $bearer=null): array {
        self::endpoint($url);
        if ($this->transport!==null) return ($this->transport)($url,$form,$bearer);
        $headers="Accept: application/json\r\n";
        if ($form!==null) $headers.="Content-Type: application/x-www-form-urlencoded\r\nAuthorization: Basic ".base64_encode(urlencode($this->clientId).':'.urlencode($this->clientSecret))."\r\n";
        if ($bearer!==null) {
            if (!preg_match('/^[A-Za-z0-9._~+\/-]+={0,2}$/D',$bearer)||strlen($bearer)>32768) throw new \RuntimeException('Invalid access token.');
            $headers.='Authorization: Bearer '.$bearer."\r\n";
        }
        $ctx=stream_context_create(['http'=>['method'=>$form===null?'GET':'POST','header'=>$headers,'content'=>$form===null?'':http_build_query($form,'','&',PHP_QUERY_RFC3986),'timeout'=>8,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]]);
        $stream=@fopen($url,'rb',false,$ctx);
        if (!$stream) throw new \RuntimeException('SkiF4er ID unavailable.');
        try { $meta=stream_get_meta_data($stream); $body=stream_get_contents($stream,262145); } finally { fclose($stream); }
        if (!preg_match('~^HTTP/\S+ 200(?: |$)~',$meta['wrapper_data'][0]??'')||!is_string($body)||strlen($body)>262144) throw new \RuntimeException('SkiF4er ID request failed.');
        $data=json_decode($body,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data)||array_is_list($data)) throw new \RuntimeException('Invalid OIDC response.');
        return $data;
    }
    public function discover(): array {
        $d=$this->json(self::ISSUER.'/.well-known/openid-configuration');
        if (($d['issuer']??null)!==self::ISSUER||!in_array('S256',$d['code_challenge_methods_supported']??[],true)||!in_array('RS256',$d['id_token_signing_alg_values_supported']??[],true)||!in_array('client_secret_basic',$d['token_endpoint_auth_methods_supported']??[],true)) throw new \RuntimeException('Unsupported SkiF4er ID configuration.');
        foreach(['authorization_endpoint','token_endpoint','jwks_uri','userinfo_endpoint'] as $key) { if (!is_string($d[$key]??null)) throw new \RuntimeException('Missing OIDC endpoint.'); self::endpoint($d[$key]); }
        return $d;
    }
    public function authorize(array $d,string $state,string $nonce,string $verifier): string {
        return self::endpoint($d['authorization_endpoint']).'?'.http_build_query(['client_id'=>$this->clientId,'redirect_uri'=>$this->redirect,'response_type'=>'code','scope'=>'openid profile email','state'=>$state,'nonce'=>$nonce,'code_challenge'=>self::challenge($verifier),'code_challenge_method'=>'S256','prompt'=>'login'],'','&',PHP_QUERY_RFC3986);
    }
    public function exchange(string $code,string $verifier,string $nonce): array {
        if ($code===''||strlen($code)>4096||preg_match('/[\x00-\x20\x7f]/',$code)) throw new \RuntimeException('Invalid authorization code.');
        $d=$this->discover();
        $t=$this->json($d['token_endpoint'],['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$this->redirect,'code_verifier'=>$verifier]);
        if (!is_string($t['id_token']??null)||!is_string($t['access_token']??null)||strcasecmp((string)($t['token_type']??''),'Bearer')!==0) throw new \RuntimeException('Invalid token response.');
        $claims=$this->verify($t['id_token'],$this->json($d['jwks_uri']),$nonce,$t['access_token']);
        $u=$this->json($d['userinfo_endpoint'],null,$t['access_token']);
        if (($u['sub']??null)!==$claims['sub']||($u['email_verified']??null)!==true||!is_string($u['email']??null)||!filter_var($u['email'],FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('OIDC user information not verified.');
        return ['sub'=>$claims['sub'],'email'=>$u['email']];
    }
    public static function validSubject(string $s): bool { return strlen($s)>=1&&strlen($s)<=255&&preg_match('/^[\x21-\x7e]+$/D',$s)===1; }
    private static function der(int $tag,string $body): string {
        $n=strlen($body); $len=$n<128?chr($n):chr(0x80+strlen($bytes=ltrim(pack('N',$n),"\0"))).$bytes;
        return chr($tag).$len.$body;
    }
    private static function integer(string $s): string { $s=ltrim($s,"\0"); if ($s==='') throw new \RuntimeException('Invalid RSA key.'); return self::der(2,(ord($s[0])&128?"\0":'').$s); }
    private static function pem(array $key): string {
        $n=self::decode($key['n']); $e=self::decode($key['e']);
        if (strlen($n)<256||strlen($n)>1024||strlen($e)>8) throw new \RuntimeException('Unsupported RSA key.');
        $rsa=self::der(48,self::integer($n).self::integer($e));
        $spki=self::der(48,hex2bin('300d06092a864886f70d0101010500').self::der(3,"\0".$rsa));
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
    }
    public function verify(string $jwt,array $jwks,string $nonce,string $accessToken): array {
        if (strlen($jwt)>65536||count($parts=explode('.',$jwt))!==3) throw new \RuntimeException('Invalid ID token.');
        [$h,$p,$s]=$parts; $header=json_decode(self::decode($h),true,16,JSON_THROW_ON_ERROR); $claims=json_decode(self::decode($p),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($header)||!is_array($claims)||($header['alg']??null)!=='RS256'||!is_string($header['kid']??null)||isset($header['crit'])||isset($header['jku'])||isset($header['jwk'])||isset($header['x5u'])) throw new \RuntimeException('Unsupported ID token header.');
        if (!is_array($jwks['keys']??null)||count($jwks['keys'])>100) throw new \RuntimeException('Invalid JWKS.');
        $keys=array_values(array_filter($jwks['keys'],fn($k)=>is_array($k)&&($k['kid']??null)===$header['kid']&&($k['kty']??null)==='RSA'&&($k['use']??'sig')==='sig'&&($k['alg']??'RS256')==='RS256'&&is_string($k['n']??null)&&is_string($k['e']??null)));
        if (count($keys)!==1||openssl_verify($h.'.'.$p,self::decode($s),self::pem($keys[0]),OPENSSL_ALGO_SHA256)!==1) throw new \RuntimeException('Invalid ID token signature.');
        $aud=$claims['aud']??null; $aud=is_string($aud)?[$aud]:$aud; $now=time();
        if (($claims['iss']??null)!==self::ISSUER||!is_array($aud)||!array_is_list($aud)||!in_array($this->clientId,$aud,true)|| (count($aud)>1&&($claims['azp']??null)!==$this->clientId)|| (isset($claims['azp'])&&$claims['azp']!==$this->clientId)||!is_int($claims['exp']??null)||$claims['exp']<=$now||!is_int($claims['iat']??null)||$claims['iat']>$now+30||$claims['iat']<$now-600|| (isset($claims['nbf'])&&(!is_int($claims['nbf'])||$claims['nbf']>$now+30))||!is_string($claims['nonce']??null)||!hash_equals($nonce,$claims['nonce'])||!is_string($claims['sub']??null)||!self::validSubject($claims['sub'])) throw new \RuntimeException('Invalid ID token claims.');
        if (isset($claims['at_hash'])&&(!is_string($claims['at_hash'])||!hash_equals(self::b64(substr(hash('sha256',$accessToken,true),0,16)),$claims['at_hash']))) throw new \RuntimeException('Invalid access token binding.');
        return $claims;
    }
}

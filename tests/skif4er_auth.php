<?php
declare(strict_types=1);
use Cajeer\Logs\Security\Skif4erOidc as O;
use Cajeer\Logs\Http\Request;
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
function fails(callable $f):bool{try{$f();return false;}catch(Throwable){return true;}}
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$details=openssl_pkey_get_details($key)['rsa'];
$jwks=['keys'=>[['kid'=>'test-key','kty'=>'RSA','alg'=>'RS256','use'=>'sig','n'=>O::b64($details['n']),'e'=>O::b64($details['e'])]]];
$nonce=O::random();$verifier=O::random();$state=O::random();$redirect='https://logs.example.test/api/v1/auth/skif4er/callback';
$claims=['iss'=>O::ISSUER,'aud'=>'test-client','sub'=>'stable-subject','nonce'=>$nonce,'iat'=>time(),'exp'=>time()+300,'at_hash'=>O::b64(substr(hash('sha256','test-access-token',true),0,16))];
$sign=function(array $claims,array $header=['alg'=>'RS256','kid'=>'test-key'])use($key):string{$p=O::b64(json_encode($header,JSON_THROW_ON_ERROR)).'.'.O::b64(json_encode($claims,JSON_THROW_ON_ERROR));openssl_sign($p,$sig,$key,OPENSSL_ALGO_SHA256);return $p.'.'.O::b64($sig);};
$d=['issuer'=>O::ISSUER,'authorization_endpoint'=>O::ISSUER.'/protocol/openid-connect/auth','token_endpoint'=>O::ISSUER.'/protocol/openid-connect/token','userinfo_endpoint'=>O::ISSUER.'/protocol/openid-connect/userinfo','jwks_uri'=>O::ISSUER.'/protocol/openid-connect/certs','code_challenge_methods_supported'=>['S256'],'id_token_signing_alg_values_supported'=>['RS256'],'token_endpoint_auth_methods_supported'=>['client_secret_basic']];
$token=$sign($claims);$requests=[];$userInfo=['sub'=>'stable-subject','email'=>'verified@example.test','email_verified'=>true];
$client=new O('test-client','test-secret',$redirect,function($url,$form,$bearer)use(&$requests,&$d,$jwks,$token,&$userInfo){$requests[]=[$url,$form,$bearer];return match($url){O::ISSUER.'/.well-known/openid-configuration'=>$d,$d['token_endpoint']=>['id_token'=>$token,'access_token'=>'test-access-token','token_type'=>'Bearer'],$d['jwks_uri']=>$jwks,$d['userinfo_endpoint']=>$userInfo,default=>throw new RuntimeException('unexpected endpoint')};});
check(strlen($state)===43&&$state!==$nonce&&$nonce!==$verifier,'independent high entropy secrets');
check(O::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk')==='E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM','RFC 7636 S256 vector');
$auth=$client->authorize($client->discover(),$state,$nonce,$verifier);parse_str(parse_url($auth,PHP_URL_QUERY),$params);
check($params['code_challenge']===O::challenge($verifier)&&$params['code_challenge_method']==='S256'&&$params['nonce']===$nonce&&$params['redirect_uri']===$redirect&&!str_contains($auth,'test-secret')&&!str_contains($auth,$verifier),'authorization uses PKCE and nonce without exposing credentials');
check($client->exchange('test-code',$verifier,$nonce)===['sub'=>'stable-subject','email'=>'verified@example.test'],'discovery, exchange, signed JWT and userinfo subject match');
check($requests[2][1]['code_verifier']===$verifier&&$requests[2][1]['redirect_uri']===$redirect&&$requests[4][2]==='test-access-token','back-channel exchange and bearer userinfo');
foreach(['iss'=>'https://evil.test','aud'=>'another-client','nonce'=>'wrong','exp'=>time()-1,'iat'=>time()+100,'sub'=>'','azp'=>'other','at_hash'=>'wrong'] as $k=>$v)check(fails(fn()=>$client->verify($sign(array_replace($claims,[$k=>$v])),$jwks,$nonce,'test-access-token')),'reject signed invalid '.$k);
check(fails(fn()=>$client->verify($sign($claims,['alg'=>'none','kid'=>'test-key']),$jwks,$nonce,'test-access-token')),'reject unsigned algorithm');
$bad=explode('.',$token);$bad[2]=O::b64(random_bytes(256));check(fails(fn()=>$client->verify(implode('.',$bad),$jwks,$nonce,'test-access-token')),'reject forged RSA signature');
check(fails(fn()=>$client->verify($token,['keys'=>[$jwks['keys'][0],$jwks['keys'][0]]],$nonce,'test-access-token')),'reject ambiguous JWKS kid');
$userInfo['sub']='other';check(fails(fn()=>$client->exchange('test-code',$verifier,$nonce)),'reject mismatched userinfo');$userInfo['sub']='stable-subject';$userInfo['email_verified']=false;check(fails(fn()=>$client->exchange('test-code',$verifier,$nonce)),'reject unverified userinfo');
foreach(['https://evil.test/realms/skif4er/token','http://id.skif4er.ru/realms/skif4er/token','https://id.skif4er.ru/realms/skif4er/../other','https://id.skif4er.ru:444/realms/skif4er/token'] as $bad){$d['token_endpoint']=$bad;check(fails(fn()=>$client->discover()),'reject untrusted discovery endpoint');}
$app->config()->set('skif4er.enabled',false);$app->config()->set('app.env','test');
check(json_decode($app->handle(new Request('GET','/api/v1/auth/skif4er/status',secureTransport:true))->body,true)['enabled']===false,'disabled login discovery');
check($app->handle(new Request('GET','/api/v1/auth/skif4er/start',secureTransport:true))->status===404,'disabled flow denied without database');
$app->config()->set('skif4er.enabled',true);check($app->handle(new Request('GET','/api/v1/auth/skif4er/start'))->status===403,'HTTPS mandatory');
foreach(['GET /account','POST /link','POST /link/finish','POST /unlink','POST /revoke'] as $route){[$method,$path]=explode(' ',$route,2);check($app->handle(new Request($method,'/api/v1/auth/skif4er'.$path,secureTransport:true))->status===401,'external account action requires local session '.$path);}
check($app->handle(new Request('GET','/api/v1/auth/skif4er/account',['Authorization'=>'Bearer cl_'.str_repeat('a',64)],secureTransport:true))->status===403,'API token cannot manage identity');
echo "SkiF4er OIDC: $n checks passed\n";

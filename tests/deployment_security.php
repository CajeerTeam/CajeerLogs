<?php
declare(strict_types=1);
use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Security\DeploymentSecurity;
use Cajeer\Logs\Http\HttpException;
$app=require dirname(__DIR__).'/core/bootstrap.php';
$n=0;
function check(bool $ok,string $name): void { global $n; if(!$ok) throw new RuntimeException($name); ++$n; echo "PASS $name\n"; }
function rejected(callable $fn,int $status): bool { try {$fn();}catch(HttpException $e){return $e->status===$status;}return false; }
function invalid(callable $fn):bool {try{$fn();}catch(InvalidArgumentException){return true;}return false;}
$c=new ConfigRepository(['app'=>['env'=>'production','url'=>'https://logs.example.test:8443'],'api'=>['prefix'=>'/api/v1'],'security'=>['trusted_proxies'=>'192.0.2.0/24,2001:db8:1::/64,127.0.0.1'],'database'=>['sslmode'=>'disable']]);
$d=new DeploymentSecurity($c);
check($d->trusted('192.0.2.255')&&!$d->trusted('192.0.3.0')&&$d->trusted('::ffff:192.0.2.9'),'CIDR and mapped IPv4');
check($d->trusted('2001:db8:1::a')&&!$d->trusted('2001:db8:2::a'),'IPv6 CIDR');
$h=['X-FORWARDED-FOR'=>'203.0.113.8','X-FORWARDED-PROTO'=>'https'];
check($d->transport('198.51.100.3',false,$h)===['address'=>'198.51.100.3','secure'=>false],'untrusted spoof ignored');
check($d->transport('192.0.2.9',false,$h)===['address'=>'203.0.113.8','secure'=>true],'trusted proxy TLS and client');
check($d->transport('192.0.2.9',true,['X-FORWARDED-PROTO'=>'http','X-FORWARDED-FOR'=>'203.0.113.8'])['secure']===false,'backend TLS cannot upgrade external HTTP');
foreach (['','203.0.113.8, 192.0.2.9','203.0.113.8:80','unknown',' 203.0.113.8'] as $value) check(rejected(fn()=>$d->transport('192.0.2.9',false,['X-FORWARDED-FOR'=>$value,'X-FORWARDED-PROTO'=>'https']),400),'reject malformed client '.json_encode($value));
check(rejected(fn()=>$d->transport('192.0.2.9',false,[]),400),'missing trusted headers');
check(rejected(fn()=>$d->transport('192.0.2.9',false,['X-FORWARDED-FOR'=>'203.0.113.8','X-FORWARDED-PROTO'=>'https,http']),400),'reject ambiguous proto');
$d->assertRequest('GET','/admin/','LOGS.EXAMPLE.TEST:8443',true); check(true,'exact configured origin');
foreach (['evil.example.test','logs.example.test','logs.example.test:443','logs.example.test:8443,evil','logs.example.test:8443@evil',null] as $host) check(rejected(fn()=>$d->assertRequest('GET','/admin/',$host,true),400),'reject wrong Host '.json_encode($host));
check(rejected(fn()=>$d->assertRequest('POST','/api/v1/ingest','logs.example.test:8443',false),403),'no insecure protected requests');
$d->assertRequest('GET','/ready','localhost',false); check(true,'internal HTTP readiness');
foreach (['0.0.0.0/0','::/0','127.0.0.1/33','bad','192.0.2.1,','192.0.2.1/1/2','::ffff:192.0.2.9/128'] as $bad) { $c->set('security.trusted_proxies',$bad);check(invalid(fn()=>new DeploymentSecurity($c)),'invalid network '.$bad); }
$c->set('security.trusted_proxies','');$c->set('database.require_tls',true);check(invalid(fn()=>new DeploymentSecurity($c)),'remote DB cannot downgrade TLS');
$c->set('database.sslmode','verify-full');check(invalid(fn()=>new DeploymentSecurity($c)),'remote DB requires CA');
$c->set('database.sslrootcert',__FILE__);check((new DeploymentSecurity($c))->snapshot()['database_tls_required']===true,'explicit verified DB TLS config');
echo "Deployment security: $n checks passed\n";

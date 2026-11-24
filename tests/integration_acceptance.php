<?php
declare(strict_types=1);
use Cajeer\Logs\Operations\IntegrationAcceptance;
use Cajeer\Logs\Security\Skif4erOidc;
use Cajeer\Logs\AI\OpenAIClient;
use Cajeer\Logs\Update\{GitHubReleaseClient,UpdateResolver};
use Cajeer\Logs\Http\{Request,HttpException};
$app=require dirname(__DIR__).'/core/bootstrap.php';$app->boot();$cfg=$app->config();
$n=0;function check(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$label);++$n;echo "PASS $label\n";}
foreach(['ai.enabled'=>true,'ai.model'=>'test-model','ai.api_key'=>'fixture-key','skif4er.enabled'=>true,'skif4er.client_id'=>'fixture-client','skif4er.client_secret'=>'fixture-secret','app.url'=>'https://logs.example.test','updates.channel'=>'stable'] as $k=>$v)$cfg->set($k,$v);
$calls=[];$issuer=Skif4erOidc::ISSUER;
$oidc=function($url,$form,$bearer)use(&$calls,$issuer){$calls[]='oidc';check($url===$issuer.'/.well-known/openid-configuration'&&$form===null&&$bearer===null,'only public OIDC discovery');return ['issuer'=>$issuer,'code_challenge_methods_supported'=>['S256'],'id_token_signing_alg_values_supported'=>['RS256'],'token_endpoint_auth_methods_supported'=>['client_secret_basic'],'authorization_endpoint'=>$issuer.'/protocol/openid-connect/auth','token_endpoint'=>$issuer.'/protocol/openid-connect/token','jwks_uri'=>$issuer.'/protocol/openid-connect/certs','userinfo_endpoint'=>$issuer.'/protocol/openid-connect/userinfo'];};
$ai=function($url,$body,$key)use(&$calls){$calls[]='ai';$d=json_decode($body,true);check($url===OpenAIClient::URL&&$key==='fixture-key'&&$d['store']===false&&!str_contains($body,'fixture-key')&&!str_contains($body,'project_id')&&!isset($d['tools']),'live probe sends synthetic text only');return [200,json_encode(['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'provider-secret-text']]]]])];};
$resolver=new UpdateResolver(null,new GitHubReleaseClient(function($url)use(&$calls){$calls[]='github';return [200,'[]'];}));
$probe=new IntegrationAcceptance($cfg,$app->version(),$ai,$oidc,$resolver);
$report=$probe->run(false);check($report['status']==='ok'&&$report['integrations']['openai']['status']==='configured'&&$calls===[]&&!$report['ga_certified'],'offline mode never contacts providers');
$report=$probe->run(true);check($report['status']==='ok'&&$calls===['ai','oidc','github']&&array_column($report['integrations'],'status')===['passed','passed','passed'],'all three controlled live probes');
check(!str_contains(json_encode($report),'provider-secret-text')&&!str_contains(json_encode($report),'fixture-key')&&!str_contains(json_encode($report),'fixture-secret')&&!$report['integrations']['skif4er']['interactive_login_verified']&&!$report['integrations']['github']['signed_install_verified']&&!$report['ga_certified'],'no secrets or false certification claims');
$bad=new IntegrationAcceptance($cfg,$app->version(),fn()=>[429,'{}'],fn()=>throw new RuntimeException('fixture-secret'),$resolver);$report=$bad->run(true);
check($report['status']==='failed'&&$report['integrations']['openai']['reason']==='rate_limited'&&$report['integrations']['skif4er']['status']==='failed'&&$report['integrations']['github']['status']==='passed'&&!str_contains(json_encode($report),'fixture-secret'),'provider failures isolated and sanitized');
$cfg->set('ai.enabled',false);$cfg->set('skif4er.enabled',false);$calls=[];$report=$probe->run(true);check($calls===['github']&&$report['integrations']['openai']['status']==='disabled'&&$report['integrations']['skif4er']['status']==='disabled','disabled integrations never contacted');
$cfg->set('skif4er.enabled',true);$cfg->set('skif4er.client_secret','');check(json_decode($app->handle(new Request('GET','/api/v1/auth/skif4er/status',secureTransport:true))->body,true)['enabled']===false,'misconfigured login is hidden');
$cfg->set('skif4er.client_secret','fixture-secret');check(json_decode($app->handle(new Request('GET','/api/v1/auth/skif4er/status',secureTransport:true))->body,true)['enabled']===true,'valid client advertised without provider request');
foreach([['status'=>'completed','output'=>['bad']],['status'=>'completed','output'=>[['type'=>'message','role'=>'assistant','content'=>['bad']]]],['status'=>'completed','output'=>[['type'=>'function_call'],['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>'unexpected']]]]] ] as $bad){try{(new OpenAIClient('fixture-key',fn()=>[200,json_encode($bad)]))->answer([]);throw new RuntimeException('unexpected response accepted');}catch(HttpException $e){check($e->status===503,'malformed or unexpected provider output rejected');}}
echo "Integrated acceptance: $n checks passed\n";

<?php
declare(strict_types=1);
namespace Cajeer\Logs\Operations;

use Cajeer\Logs\AI\OpenAIClient;
use Cajeer\Logs\Config\ConfigRepository;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Security\Skif4erOidc;
use Cajeer\Logs\Update\UpdateResolver;

/** Explicit operator-side connectivity check. No log records, user identity,
 * credentials, upstream response text or provider tokens appear in the report.
 * This is not a substitute for an interactive OIDC login or update rehearsal. */
final class IntegrationAcceptance
{
    public function __construct(private readonly ConfigRepository $config,private readonly string $version,
        private readonly ?\Closure $openaiTransport=null,private readonly ?\Closure $oidcTransport=null,
        private readonly ?UpdateResolver $resolver=null) {}

    public function run(bool $live): array
    {
        $results=[]; $cfg=$this->config;
        $probe=static function(string $name, bool $enabled, callable $check) use (&$results,$live): void {
            if (!$enabled) { $results[$name]=['status'=>'disabled'];return; }
            try { $results[$name]=['status'=>$live?'passed':'configured']+$check($live); }
            catch (HttpException $e) { $results[$name]=['status'=>'failed','reason'=>$e->status===429?'rate_limited':'provider_unavailable']; }
            catch (\Throwable) { $results[$name]=['status'=>'failed','reason'=>'configuration_or_provider_unavailable']; }
        };
        $probe('openai',$cfg->get('ai.enabled')===true,function(bool $live)use($cfg):array {
            $model=$cfg->get('ai.model');$key=$cfg->get('ai.api_key');
            if (!is_string($model)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D',$model)||!is_string($key)) throw new \InvalidArgumentException('Invalid model.');
            $client=new OpenAIClient($key,$this->openaiTransport);
            if ($live) $client->answer(['model'=>$model,'input'=>'Respond with the word OK. This is a connectivity check, not log data.','store'=>false,'max_output_tokens'=>256]);
            return ['model'=>$model,'scope'=>$live?'synthetic_response':'configuration','messages_included'=>false];
        });
        $probe('skif4er',$cfg->get('skif4er.enabled')===true,function(bool $live)use($cfg):array {
            $url=rtrim((string)$cfg->get('app.url'),'/');$parts=parse_url($url);
            if (!$parts||($parts['scheme']??null)!=='https'||!isset($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||($parts['path']??'')!=='') throw new \InvalidArgumentException('HTTPS origin required.');
            $client=new Skif4erOidc((string)$cfg->get('skif4er.client_id'),(string)$cfg->get('skif4er.client_secret'),$url.$cfg->get('api.prefix').'/auth/skif4er/callback',$this->oidcTransport);
            if ($live) $client->discover();
            return ['scope'=>$live?'oidc_discovery':'configuration','interactive_login_verified'=>false];
        });
        $probe('github',true,function(bool $live)use($cfg):array {
            $channel=$cfg->get('updates.channel');if(!in_array($channel,['stable','preview'],true))throw new \InvalidArgumentException('Invalid channel.');
            if (!$live) return ['channel'=>$channel,'scope'=>'configuration'];
            $release=($this->resolver??new UpdateResolver())->check($this->version,$channel,true);
            if (!in_array($release['status'],['ok','no_releases'],true)) throw new \RuntimeException('Discovery failed.');
            return ['channel'=>$channel,'scope'=>'release_discovery','candidate'=>$release['candidate'],'update_available'=>$release['update_available'],'signed_install_verified'=>false];
        });
        $ok=!in_array('failed',array_column($results,'status'),true);
        return ['version'=>$this->version,'status'=>$ok?'ok':'failed','live'=>$live,'integrations'=>$results,'ga_certified'=>false];
    }
}

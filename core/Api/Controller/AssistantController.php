<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;
use Cajeer\Logs\AI\{Assistant,OpenAIClient};
use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\MigrationRunner;
use Cajeer\Logs\Http\{HttpException,JsonResponse,Request,Response,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\{Identity,IngestAuthorization,RateLimiter,RateLimitPolicy};
use PDO;

final class AssistantController
{
    public const ROUTES=['GET /assistant/status'=>'assistantStatus','POST /assistant/chat'=>'assistantChat'];
    public static function register(Router $router,Application $app,string $prefix): void {
        foreach(self::ROUTES as $route=>$action) { [$method,$path]=explode(' ',$route,2); $router->add($method,$prefix.$path,static fn(Request $r):Response=>(new self($app))->run($r,$action)); }
    }
    public function __construct(private readonly Application $app,private readonly ?\Closure $transport=null) {}
    public function run(Request $r,string $action): Response
    {
        $config=$this->app->config(); $url=parse_url($config->get('app.url')); $secure=($url['scheme']??null)==='https';
        if ($config->get('app.env')==='production'&&(!$secure||!$r->secureTransport)) throw new HttpException(403,'https_required','HTTPS is required.');
        $credential=Identity::credentials($r); if ($credential['kind']!=='session') Identity::forbidden();
        $enabled=$config->get('ai.enabled')===true;
        $model=(string)$config->get('ai.model'); $key=(string)$config->get('ai.api_key');
        $configured=$key!==''&&strlen($key)<=512&&!preg_match('/[^\x21-\x7e]/',$key)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D',$model);
        $messages=$config->get('ai.include_messages')===true;
        $chat=$action==='assistantChat';
        if ($chat && strlen($r->body())>24000) throw new HttpException(413,'payload_too_large','Assistant request too large.');
        $request=$chat?Assistant::validate($r->json()):null;
        $project=$chat?$request['query']->filters['project_id']:\Cajeer\Logs\Log\LogQuery::uuid(is_string($r->query['project_id']??null)?$r->query['project_id']:'');
        $pdo=$this->app->container()->get(PDO::class); $guard=$this->app->container()->get(MigrationRunner::class)->guard();
        $origin=$url['scheme'].'://'.$url['host'].(isset($url['port'])&&!($secure&&$url['port']===443)&&!(!$secure&&$url['port']===80)?':'.$url['port']:'');
        $identity=Identity::fromConfig($pdo,$secure,$origin,$config); $rate=new RateLimiter($pdo); $policy=RateLimitPolicy::fromConfig($config);
        $policy->peer($rate,$r); $p=$identity->authenticate($r,$credential);
        $policy->principal($rate,'session',$p['id'],$chat?'search':null);
        $identity->permission($p,'logs:read',$project);
        if (!(new \Cajeer\Logs\Database\Repository\ProjectRepository($pdo))->find($project)) throw new HttpException(404,'not_found','Project not found.');
        if (!$chat) return new JsonResponse(['enabled'=>$enabled,'configured'=>$enabled&&(bool)$configured,'messages_included'=>$messages,'model'=>$enabled&&$configured?$model:null],200,['Cache-Control'=>'no-store']);
        if (!$enabled) throw new HttpException(403,'ai_disabled','AI Assistant is disabled.');
        if (!$configured) throw new HttpException(503,'ai_unavailable','OpenAI is not configured.');
        // Fixed global and user budgets, charged even for failed upstream calls. No retries.
        $rate->enforce('assistant-user:'.$p['id'],6,60); $rate->enforce('assistant-global',30,60); $rate->enforce('assistant-user-hour:'.$p['id'],60,3600); $rate->enforce('assistant-global-hour',300,3600);
        $context=Assistant::sample($pdo,$request['query'],min((int)$config->get('api.search_timeout_ms'),(int)$config->get('database.statement_timeout_ms')),$messages);
        // Recheck session and current project grants before any external disclosure.
        $p=$identity->authenticate($r,$credential,false,false,false); $identity->permission($p,'logs:read',$project);
        $audit=new AuditLogger($pdo); $audit->record('assistant.requested',['request_id'=>$r->requestId,'event_count'=>count($context['events']),'messages_included'=>$messages],$p['id'],$project);
        try { $answer=(new OpenAIClient($key,$this->transport))->answer(Assistant::payload($model,$request,$context)); }
        catch (\Throwable $e) { $audit->record('assistant.failed',['request_id'=>$r->requestId],$p['id'],$project,'failure'); throw $e; }
        $p=$identity->authenticate($r,$credential,false,false,false); $identity->permission($p,'logs:read',$project);
        $audit->record('assistant.completed',['request_id'=>$r->requestId,'usage'=>$answer['usage']],$p['id'],$project);
        // Return only known, local evidence IDs. Never forward provider-supplied links or tools.
        $evidence=array_map(static fn($event)=>array_intersect_key($event,array_flip(['id','timestamp','level'])),$context['events']);
        return new JsonResponse($answer+['evidence'=>$evidence,'truncated'=>$context['truncated'],'messages_included'=>$messages,'model'=>$model],200,['Cache-Control'=>'no-store']);
    }
}

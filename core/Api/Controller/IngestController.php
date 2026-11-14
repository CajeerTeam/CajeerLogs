<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Database\TransactionManager;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};
use Cajeer\Logs\Security\{Identity,RateLimiter,SignedPayload};
use PDO;
use PDOException;

final class IngestController
{
    public const ROUTES = ['POST /ingest'=>'ingestEvents', 'POST /ingest/async'=>'enqueueEvents', 'GET /ingest/jobs/{job}'=>'getIngestJob'];
    public function __construct(private readonly Application $app) {}

    public static function register(Router $router, Application $app, string $prefix): void
    {
        $router->add('POST',$prefix.'/ingest',fn(Request $r): Response => (new self($app))->ingest($r));
        $router->add('POST',$prefix.'/ingest/async',fn(Request $r): Response => (new self($app))->ingest($r,true));
        $router->add('GET',$prefix.'/ingest/jobs/{job}',fn(Request $r): Response => (new self($app))->job($r));
    }

    public function job(Request $r): Response
    {
        $config=$this->app->config();
        if ($config->get('app.env')==='production' && (!$r->secureTransport || parse_url($config->get('app.url'),PHP_URL_SCHEME)!=='https')) { throw new HttpException(403,'https_required','HTTPS is required for ingest.'); }
        $credential=Identity::credentials($r);
        if ($credential['kind']!=='token') { Identity::forbidden(); }
        $id=$r->parameter('job')??'';
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$id) || strlen($id)===19 && strcmp($id,'9223372036854775807')>0) { throw new HttpException(422,'invalid_job','Invalid job ID.'); }
        try {
            $pdo=$this->app->container()->get(PDO::class);
            $schemaGuard=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
            $rate=new RateLimiter($pdo);
            $rate->enforce('job-status-ip:'.$r->remoteAddress,$config->get('api.rate_limit'),60);
            $p=(new Identity($pdo,true,''))->authenticate($r,$credential);
            if ($p['token']['project_id']===null || !in_array('logs:write',$p['token']['scopes'],true)) { Identity::forbidden(); }
            $rate->enforce('job-status-token:'.$p['id'],$config->get('api.rate_limit'),60);
            $row=$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class)->status($id,$p['id']);
            if ($row===null) { throw new HttpException(404,'job_not_found','Job not found.'); }
            return new JsonResponse($row);
        } catch (DatabaseException|PDOException $e) { throw new HttpException(503,'database_unavailable','Job storage unavailable.'); }
    }

    private function admit(TransactionManager $tx,PDO $pdo,string $project,array $events,array $sources,int $bytes): ?HttpException
    {
        $counts=[];
        foreach ($events as $event) { $id=$sources[$event->source]; $counts[$id]=($counts[$id]??0)+1; }
        $repo=new \Cajeer\Logs\Database\Repository\SourceRepository($pdo);
        try {
            $tx->run(function () use ($pdo,$project,$events,$bytes,$counts,$repo) {
                (new \Cajeer\Logs\Database\Repository\ProjectRepository($pdo))->admit($project,$bytes,count($events));
                $repo->admit($project,$counts,$bytes);
            });
            return null;
        } catch (HttpException $e) {
            if (!in_array($e->error,['project_archived','project_payload_limit','project_quota_exceeded','source_inactive','source_payload_limit','source_quota_exceeded'],true)) throw $e;
            // The savepoint rolled back all accepted counters and quota changes. Commit
            // only rejection telemetry (and any authenticated HMAC nonce) in the outer transaction.
            $repo->recordPolicyRejection($project,$counts);
            return $e;
        }
    }

    public function ingest(Request $r, bool $async=false): Response
    {
        $config=$this->app->config();
        if ($config->get('app.env')==='production' && (!$r->secureTransport || parse_url($config->get('app.url'),PHP_URL_SCHEME)!=='https')) { throw new HttpException(403,'https_required','HTTPS is required for ingest.'); }
        if (strlen($r->body())>IngestValidator::MAX_BODY_BYTES) { throw new HttpException(413,'payload_too_large','Ingest body exceeds 1 MiB.'); }
        $credential=Identity::credentials($r);
        if ($credential['kind']!=='token') { Identity::forbidden(); }
        $key=$r->header('Idempotency-Key');
        if ($key!==null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$key)) { throw new HttpException(422,'invalid_idempotency_key','Invalid Idempotency-Key.'); }
        if ($async && $key===null) { throw new HttpException(422,'idempotency_required','Async ingest requires Idempotency-Key.'); }
        $signer=new SignedPayload();
        try {
            $pdo=$this->app->container()->get(PDO::class);
            $schemaGuard=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
            $rate=new RateLimiter($pdo);
            $rate->enforce('ingest-ip:'.$r->remoteAddress,$config->get('api.rate_limit'),60);
            $identity=new Identity($pdo,true,'');
            $p=$identity->authenticate($r,$credential);
            $project=$p['token']['project_id'];
            if ($project===null || !in_array('logs:write',$p['token']['scopes'],true)) { Identity::forbidden(); }
            $rate->enforce('ingest-token:'.$p['id'],$config->get('api.rate_limit'),60);
            $signer->verify($r,$credential['secret'],$key,$config->get('ingest.require_hmac'));
            $events=(new IngestValidator())->parse($r);
            $keyHash=$key===null ? null : hash('sha256',$key);
            $requestHash=hash_hmac('sha256',$r->body(),$credential['secret']);
            $result=(new TransactionManager($pdo))->run(function (TransactionManager $tx) use ($pdo,$identity,$r,$credential,$events,$signer,$key,$keyHash,$requestHash,$config,$async) {
                // Flush the local WAL before acknowledging; normal PostgreSQL fsync settings are required.
                // UPDATE ... RETURNING in authenticate takes the token row lock, serializing revocation and retries.
                $p=$identity->authenticate($r,$credential);
                $project=$p['token']['project_id'];
                if ($project===null) { Identity::forbidden(); }
                if (!in_array('logs:write',$p['token']['scopes'],true)) { Identity::forbidden(); }
                $repo=new LogRepository($pdo);
                $sources=$repo->resolveSources($events,$project,$p['token']['source_id']);
                // Verify freshness again after waiting on concurrent writers.
                $nonce=$signer->verify($r,$credential['secret'],$key,$config->get('ingest.require_hmac'));
                if ($nonce!==null) { $repo->claimNonce($p['id'],$nonce); }
                if ($async) {
                    if ($repo->receipt($p['id'],$keyHash,$requestHash)!==null) { throw new HttpException(409,'idempotency_conflict','Idempotency-Key already used for synchronous ingest.'); }
                    $queue=$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class);
                    if (!$queue->hasKey($p['id'],$keyHash) && ($denied=$this->admit($tx,$pdo,$project,$events,$sources,strlen($r->body())))!==null) return $denied;
                    $job=$queue->enqueue($p['id'],$project,$keyHash,$requestHash,$events,$sources);
                    $response=new JsonResponse(['accepted'=>true,'received'=>count($events)]+$job,$job['replayed']?200:202,['Location'=>$config->get('api.prefix').'/ingest/jobs/'.$job['job_id']]);
                    return $response;
                }
                if ($keyHash!==null && $this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class)->hasKey($p['id'],$keyHash)) { throw new HttpException(409,'idempotency_conflict','Idempotency-Key already used for asynchronous ingest.'); }
                $ids=$keyHash===null ? null : $repo->receipt($p['id'],$keyHash,$requestHash);
                $replayed=$ids!==null;
                if ($replayed && count($ids)!==count($events)) { throw new DatabaseException('Stored receipt count differs from request.'); }
                if (!$replayed) {
                    if (($denied=$this->admit($tx,$pdo,$project,$events,$sources,strlen($r->body())))!==null) return $denied;
                    $ids=$repo->storeBatch($events,$project,$sources,$p['id']);
                    (new \Cajeer\Logs\Integration\EventApiDispatcher($pdo))->publishCommittedLogs($project,$ids);
                    if ($keyHash!==null) { $repo->saveReceipt($p['id'],$keyHash,$requestHash,$ids); }
                }
                $response=new JsonResponse(['accepted'=>true,'received'=>count($events),'stored'=>$replayed?0:count($ids),'rejected'=>0,'event_ids'=>$ids,'replayed'=>$replayed],$replayed?200:201);
                return $response;
            }, maxAttempts: 3);
            if ($result instanceof HttpException) throw $result;
            return $result;
        } catch (DatabaseException $e) {
            throw new HttpException(503,'database_unavailable','Log storage unavailable.');
        } catch (PDOException $e) {
            $state=(string)$e->getCode();
            if (\Cajeer\Logs\Database\DatabaseFailure::unavailable($e) || str_starts_with($state,'08') || str_starts_with($state,'53') || in_array($state,['42P01','42703','55P03','57014','57P01','57P02','57P03','40001','40P01','25006'],true)) { throw new HttpException(503,'database_unavailable','Log storage unavailable.'); }
            throw $e;
        }
    }
}

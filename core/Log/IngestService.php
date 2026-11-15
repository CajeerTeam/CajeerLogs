<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Database\{TransactionManager,DatabaseException,DatabaseFailure};
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\{IngestAuthorization,IngestSigning};
use PDO;
use PDOException;

/** Shared sync/async admission and durable delivery. No retry of ambiguous writes. */
final class IngestService
{
    public function __construct(private readonly Application $app) {}

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
        $credential=IngestAuthorization::credentials($r);
        $key=$r->header('Idempotency-Key');
        if ($key!==null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$key)) { throw new HttpException(422,'invalid_idempotency_key','Invalid Idempotency-Key.'); }
        if ($async && $key===null) { throw new HttpException(422,'idempotency_required','Async ingest requires Idempotency-Key.'); }
        IngestEnvelope::validate($r, min(IngestValidator::MAX_BODY_BYTES, $config->get('app.max_body_bytes')));
        $budget=new IngestBudget($config->get('ingest.budget_ms'));
        $slot=IngestAdmission::acquire($this->app->basePath('storage/cache'),$config->get('ingest.max_inflight'));
        try {
            $budget->check();
            $pdo=$this->app->container()->get(PDO::class);
            $budget->check();
            $schemaGuard=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
            $budget->check();
            $authorization=new IngestAuthorization($pdo,$config->get('api.rate_limit'));
            $principal=$authorization->begin($r,$credential);
            $signer=new IngestSigning($pdo,$config->get('security.totp_key'));
            $signer->verify($r,$principal,$credential['secret'],$key,$config->get('ingest.require_hmac'));
            $receivedAt=time();
            // Keyed replays must not be rejected by newer validation rules. Parse only new deliveries.
            $budget->check();
            $keyHash=$key===null ? null : hash('sha256',$key);
            $requestHash=hash_hmac('sha256',$r->body(),$credential['secret']);
            $result=(new TransactionManager($pdo))->run(function (TransactionManager $tx) use ($pdo,$authorization,$principal,$r,$credential,$signer,$key,$keyHash,$requestHash,$config,$async,$budget,$receivedAt) {
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                // Flush the local WAL before acknowledging; normal PostgreSQL fsync settings are required.
                // Transactional authentication locks the token even when usage telemetry is fresh.
                $p=$authorization->locked($r,$credential,$principal);
                $project=$p->projectId;
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                $repo=new LogRepository($pdo);
                // Project lock serializes sync/async and all active members of a rotation family.
                $nonce=$signer->verify($r,$p,$credential['secret'],$key,$config->get('ingest.require_hmac'),true);
                if ($nonce!==null) { $repo->claimNonce($p->id,$nonce); }
                $digest=null;
                if ($keyHash!==null) {
                    $delivery=new DeliveryRepository($pdo);
                    $family=$delivery->namespace($p);
                    $digest=DeliveryRepository::digest($r->body(),$family['token_hash'],$config->get('security.totp_key'));
                    $previous=$delivery->lookup($p,$family['id'],$keyHash,$digest,$requestHash,$async);
                    if ($previous!==null) {
                        $count=$previous['received']??DeliveryRepository::legacyCount($r->body());
                        unset($previous['received']);
                        $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                        $authorization->locked($r,$credential,$p,false);
                        if ($async) return new JsonResponse(['accepted'=>true,'received'=>$count]+$previous,200,['Location'=>$config->get('api.prefix').'/ingest/jobs/'.$previous['job_id']]);
                        return new JsonResponse(['accepted'=>true,'received'=>$count,'stored'=>0,'rejected'=>0,'event_ids'=>$previous['event_ids'],'replayed'=>true],200);
                    }
                }
                $events=(new IngestValidator())->parse($r);
                IngestBatch::checkSize($events,$async?IngestBatch::JOB_BYTES:IngestBatch::SQL_BYTES);
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                $sources=$repo->resolveSources($events,$project,$p->sourceId);
                foreach ($sources as $sourceId) $p->assertSource($sourceId);
                if ($async) {
                    $queue=$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class);
                    $job=$queue->enqueue($p->id,$project,$keyHash,$requestHash,$events,$sources,
                        function () use ($tx,$pdo,$project,$events,$sources,$r,$budget,$config,$receivedAt) {
                            IngestValidator::checkTime($events,$config->get('ingest.max_event_age_seconds'),$config->get('ingest.max_event_future_seconds'),$receivedAt);
                            $denied=$this->admit($tx,$pdo,$project,$events,$sources,strlen($r->body()));
                            $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                            return $denied;
                        },$digest,$config->get('ingest.idempotency_seconds'));
                    if ($job instanceof HttpException) return $job;
                    $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                    $authorization->locked($r,$credential,$p,false);
                    $response=new JsonResponse(['accepted'=>true,'received'=>count($events)]+$job,$job['replayed']?200:202,['Location'=>$config->get('api.prefix').'/ingest/jobs/'.$job['job_id']]);
                    $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                    return $response;
                }
                IngestValidator::checkTime($events,$config->get('ingest.max_event_age_seconds'),$config->get('ingest.max_event_future_seconds'),$receivedAt);
                if (($denied=$this->admit($tx,$pdo,$project,$events,$sources,strlen($r->body())))!==null) return $denied;
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                $ids=$repo->storeBatch($events,$project,$sources,$p->id);
                (new \Cajeer\Logs\Integration\EventApiDispatcher($pdo))->publishCommittedLogs($project,$ids);
                if ($keyHash!==null) { $repo->saveReceipt($p->id,$keyHash,$requestHash,$ids,$digest,$config->get('ingest.idempotency_seconds')); }
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                $authorization->locked($r,$credential,$p,false);
                $response=new JsonResponse(['accepted'=>true,'received'=>count($events),'stored'=>count($ids),'rejected'=>0,'event_ids'=>$ids,'replayed'=>false],201);
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                return $response;
            }, maxAttempts: 3);
            if ($result instanceof HttpException) throw $result;
            return $result;
        } catch (DatabaseException $e) {
            throw IngestBudget::unavailable();
        } catch (PDOException $e) {
            $state=(string)($e->errorInfo[0]??$e->getCode());
            if (DatabaseFailure::unavailable($e) || str_starts_with($state,'08') || str_starts_with($state,'53') || in_array($state,['42P01','42703','55P03','57014','57P01','57P02','57P03','40001','40P01','25006'],true)) { throw IngestBudget::unavailable(); }
            throw $e;
        } finally {
            if (isset($schemaGuard)) $schemaGuard->release();
            $slot->release();
        }
    }
}

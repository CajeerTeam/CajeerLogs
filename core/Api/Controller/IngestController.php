<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{IngestValidator,LogRepository};
use Cajeer\Logs\Security\{Identity,RateLimiter,SignedPayload};
use PDO;
use PDOException;

final class IngestController
{
    public const ROUTES = ['POST /ingest'=>'ingestEvents'];
    public function __construct(private readonly Application $app) {}

    public static function register(Router $router, Application $app, string $prefix): void
    {
        $router->add('POST',$prefix.'/ingest',fn(Request $r): Response => (new self($app))->ingest($r));
    }

    public function ingest(Request $r): Response
    {
        $config=$this->app->config();
        if ($config->get('app.env')==='production' && (!$r->secureTransport || parse_url($config->get('app.url'),PHP_URL_SCHEME)!=='https')) { throw new HttpException(403,'https_required','HTTPS is required for ingest.'); }
        if (strlen($r->body())>IngestValidator::MAX_BODY_BYTES) { throw new HttpException(413,'payload_too_large','Ingest body exceeds 1 MiB.'); }
        $credential=Identity::credentials($r);
        if ($credential['kind']!=='token') { Identity::forbidden(); }
        $key=$r->header('Idempotency-Key');
        if ($key!==null && !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D',$key)) { throw new HttpException(422,'invalid_idempotency_key','Invalid Idempotency-Key.'); }
        $signer=new SignedPayload();
        try {
            $pdo=$this->app->container()->get(PDO::class);
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
            $pdo->beginTransaction();
            try {
                // Flush the local WAL before acknowledging; normal PostgreSQL fsync settings are required.
                $pdo->exec('SET LOCAL synchronous_commit = on');
                // UPDATE ... RETURNING in authenticate takes the token row lock, serializing revocation and retries.
                $p=$identity->authenticate($r,$credential);
                $project=$p['token']['project_id'];
                if ($project===null) { Identity::forbidden(); }
                $sources=[];
                foreach ($events as $event) {
                    if (!isset($sources[$event->source])) {
                        $source=$identity->sql('SELECT id FROM sources WHERE project_id=:project AND slug=:slug',['project'=>$project,'slug'=>$event->source])->fetchColumn();
                        if (!is_string($source)) { Identity::forbidden(); }
                        $identity->permission($p,'logs:write',$project,$source);
                        $sources[$event->source]=$source;
                    }
                }
                $repo=new LogRepository($pdo);
                // Verify freshness again after waiting on concurrent writers.
                $nonce=$signer->verify($r,$credential['secret'],$key,$config->get('ingest.require_hmac'));
                if ($nonce!==null) { $repo->claimNonce($p['id'],$nonce); }
                $ids=$keyHash===null ? null : $repo->receipt($p['id'],$keyHash,$requestHash);
                $replayed=$ids!==null;
                if (!$replayed) {
                    $ids=$repo->storeBatch($events,$project,$sources,$p['id']);
                    (new \Cajeer\Logs\Integration\EventApiDispatcher($pdo))->publishCommittedLogs($project,$ids);
                    if ($keyHash!==null) { $repo->saveReceipt($p['id'],$keyHash,$requestHash,$ids); }
                }
                $response=new JsonResponse(['accepted'=>true,'received'=>count($events),'stored'=>$replayed?0:count($ids),'rejected'=>0,'event_ids'=>$ids,'replayed'=>$replayed],$replayed?200:201);
                $pdo->commit();
                return $response;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            }
        } catch (DatabaseException $e) {
            throw new HttpException(503,'database_unavailable','Log storage unavailable.');
        } catch (PDOException $e) {
            $state=(string)$e->getCode();
            if (str_starts_with($state,'08') || str_starts_with($state,'53') || in_array($state,['42P01','42703','55P03','57014','57P01','57P02','57P03','40001','40P01','25006'],true)) { throw new HttpException(503,'database_unavailable','Log storage unavailable.'); }
            throw $e;
        }
    }
}

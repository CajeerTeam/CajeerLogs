<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;


use Cajeer\Logs\Database\{DatabaseException,TransactionManager};
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\IngestAuthorization;
use Cajeer\Logs\Log\IngestBudget;
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
        $credential=IngestAuthorization::credentials($r);
        $id=$r->parameter('job')??'';
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$id) || strlen($id)===19 && strcmp($id,'9223372036854775807')>0) { throw new HttpException(422,'invalid_job','Invalid job ID.'); }
        try {
            $budget=new IngestBudget($config->get('ingest.budget_ms'));
            $pdo=$this->app->container()->get(PDO::class);
            $schemaGuard=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
            $authorization=new IngestAuthorization($pdo,$config->get('api.rate_limit'),\Cajeer\Logs\Security\RateLimitPolicy::fromConfig($config));
            $principal=$authorization->begin($r,$credential);
            $row=(new TransactionManager($pdo))->run(function () use ($authorization,$principal,$r,$credential,$id,$pdo,$budget,$config) {
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                $p=$authorization->locked($r,$credential,$principal);
                $owner=(new \Cajeer\Logs\Log\DeliveryRepository($pdo))->jobOwner($p,$id);
                $result=$owner===null?null:$this->app->container()->get(\Cajeer\Logs\Queue\PostgresQueueDriver::class)->status($id,$owner,$p->projectId);
                $budget->limitSql($pdo,(int)$config->get('database.statement_timeout_ms'));
                $authorization->locked($r,$credential,$p,false);
                return $result;
            });
            if ($row===null) { throw new HttpException(404,'job_not_found','Job not found.'); }
            return new JsonResponse($row);
        } catch (DatabaseException|PDOException $e) { throw new HttpException(503,'database_unavailable','Job storage unavailable.',['Retry-After'=>'5']); }
        finally { if (isset($schemaGuard)) $schemaGuard->release(); }
    }

    public function ingest(Request $r, bool $async=false): Response
    {
        return $this->app->container()->get(\Cajeer\Logs\Log\IngestService::class)->ingest($r,$async);
    }
}

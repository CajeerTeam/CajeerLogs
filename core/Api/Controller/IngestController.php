<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;


use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\{Request,Response,JsonResponse,HttpException,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Security\{Identity,RateLimiter};
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
        } catch (DatabaseException|PDOException $e) { throw new HttpException(503,'database_unavailable','Job storage unavailable.',['Retry-After'=>'5']); }
    }

    public function ingest(Request $r, bool $async=false): Response
    {
        return $this->app->container()->get(\Cajeer\Logs\Log\IngestService::class)->ingest($r,$async);
    }
}

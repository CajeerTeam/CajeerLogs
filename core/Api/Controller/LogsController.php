<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\{HttpException,JsonResponse,Request,Response,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{LogQuery,LogExport,LogRepository};
use Cajeer\Logs\Security\{Identity,RateLimiter};
use PDO;
use PDOException;

final class LogsController
{
    public const ROUTES=['GET /logs'=>'searchLogs','GET /logs/stats'=>'logStatistics','GET /logs/export'=>'exportLogs','GET /logs/{event}'=>'getLogEvent'];
    public function __construct(private readonly Application $app) {}
    public static function register(Router $router,Application $app,string $prefix): void
    {
        $controller=new self($app);
        foreach (['/logs'=>'list','/logs/stats'=>'stats','/logs/export'=>'export','/logs/{event}'=>'detail'] as $path=>$mode) {
            $router->add('GET',$prefix.$path,fn(Request $r): Response=>$controller->run($r,$mode));
        }
    }
    public function run(Request $r,string $mode): Response
    {
        $config=$this->app->config();
        if ($config->get('app.env')==='production' && (!$r->secureTransport||parse_url($config->get('app.url'),PHP_URL_SCHEME)!=='https')) { throw new HttpException(403,'https_required','HTTPS is required for log access.'); }
        $credential=Identity::credentials($r);
        $query=LogQuery::parse($r->query,$mode);
        $event=$mode==='detail'?LogQuery::uuid($r->parameter('event')??''):null;
        try {
            $pdo=$this->app->container()->get(PDO::class);
            $schemaGuard=$this->app->container()->get(\Cajeer\Logs\Database\MigrationRunner::class)->guard();
            $rate=new RateLimiter($pdo);
            $rate->enforce('logs-ip:'.$r->remoteAddress,$config->get('api.rate_limit'),60);
            $identity=new Identity($pdo,true,'',28800,'',$config->get('security.require_admin_totp'));
            $p=$identity->authenticate($r,$credential);
            $rate->enforce('logs-'.$p['kind'].':'.$p['id'],$config->get('api.rate_limit'),60);
            $project=$query->filters['project_id']; $source=$query->filters['source_id']??null;
            if ($p['kind']==='token' && $p['token']['source_id']!==null) {
                if ($source!==null && $source!==$p['token']['source_id']) { Identity::forbidden(); }
                $source=$p['token']['source_id'];
            }
            $identity->permission($p,'logs:read',$project,$source);
            $query=$query->scoped($source);
            $engine=new LogRepository($pdo);
            if ($mode==='detail') {
                $row=$engine->find($query,$event);
                if ($row===null) { throw new HttpException(404,'not_found','Log event not found.'); }
                return new JsonResponse(['data'=>$row]);
            }
            if ($mode==='stats') { return new JsonResponse($engine->statistics($query)); }
            $page=$engine->page($query);
            if ($mode==='export') {
                $headers=['Content-Disposition'=>'attachment; filename="cajeerlogs.'.$query->format.'"'];
                if ($page['next_cursor']!==null) { $headers['X-Next-Cursor']=$page['next_cursor']; }
                if ($query->format==='csv') { return new Response(LogExport::csv($page['items']),200,['Content-Type'=>'text/csv; charset=utf-8']+$headers); }
                return new JsonResponse($page,200,$headers);
            }
            return new JsonResponse($page);
        } catch (DatabaseException $e) {
            throw new HttpException(503,'database_unavailable','Log storage unavailable.');
        } catch (PDOException $e) {
            $state=(string)$e->getCode();
            if (str_starts_with($state,'08')||str_starts_with($state,'53')||in_array($state,['42P01','42703','55P03','57014','57P01','57P02','57P03','40001','40P01','25006'],true)) { throw new HttpException(503,'database_unavailable','Log storage unavailable.'); }
            if (str_starts_with($state,'22')) { throw new HttpException(422,'invalid_query','Invalid query value.'); }
            throw $e;
        }
    }
}

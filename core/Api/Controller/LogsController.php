<?php
declare(strict_types=1);
namespace Cajeer\Logs\Api\Controller;

use Cajeer\Logs\Database\{DatabaseException,DatabaseFailure};
use Cajeer\Logs\Http\{HttpException,JsonResponse,Request,Response,Router};
use Cajeer\Logs\Kernel\Application;
use Cajeer\Logs\Log\{LogQuery,LogExport,LogRepository,SearchRuntime,PageCursor,ErrorAnalytics};
use Cajeer\Logs\Security\{Identity,RateLimiter};
use PDO;
use PDOException;

final class LogsController
{
    public const ROUTES=['GET /logs'=>'searchLogs','GET /logs/stats'=>'logStatistics','GET /logs/errors'=>'errorAnalytics','GET /logs/errors/report'=>'exportErrorReport','GET /logs/export'=>'exportLogs','GET /logs/{event}'=>'getLogEvent'];
    public function __construct(private readonly Application $app) {}
    public static function register(Router $router,Application $app,string $prefix): void
    {
        $controller=new self($app);
        foreach (['/logs'=>'list','/logs/stats'=>'stats','/logs/errors'=>'errors','/logs/errors/report'=>'error_report','/logs/export'=>'export','/logs/{event}'=>'detail'] as $path=>$mode) {
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
            $policy=\Cajeer\Logs\Security\RateLimitPolicy::fromConfig($config);
            $policy->peer($rate,$r);
            $rate->enforce('logs-ip:'.\Cajeer\Logs\Security\IngestAuthorization::peerKey($r),$config->get('api.rate_limit'),60);
            $identity=Identity::fromConfig($pdo,true,'',$config);
            $p=$identity->authenticate($r,$credential);
            $rate->enforce('logs-'.$p['kind'].':'.$p['id'],$config->get('api.rate_limit'),60);
            $policy->principal($rate,$p['kind'],$p['id'],match($mode){'stats','errors'=>'analytics','export','error_report'=>'export',default=>'search'});
            $project=$query->filters['project_id']; $source=$query->filters['source_id']??null;
            if ($p['kind']==='token' && $p['token']['source_id']!==null) {
                if ($source!==null && $source!==$p['token']['source_id']) { Identity::forbidden(); }
                $source=$p['token']['source_id'];
            }
            $identity->permission($p,'logs:read',$project,$source);
            if (in_array($mode,['export','error_report'],true) && $p['kind']==='session') $identity->permission($p,'logs:export',$project,$source);
            $query=$query->scoped($source);
            $signer=in_array($mode,['list','export'],true)?PageCursor::forPrincipal($pdo,(string)$config->get('security.totp_key'),$p):null;
            $state=$signer?->open($query);
            $budget=min($config->get('api.search_timeout_ms'),(int)$config->get('database.statement_timeout_ms'));
            $response=(new SearchRuntime($pdo,$budget))->run($query,function (LogRepository $engine,callable $limit) use ($mode,$query,$event,$signer,$state,$r): Response {
                if ($mode==='detail') {
                    $row=$engine->find($query,$event);
                    if ($row===null) { throw new HttpException(404,'not_found','Log event not found.'); }
                    return new JsonResponse(['data'=>$row],200,['Cache-Control'=>'no-store']);
                }
                if ($mode==='stats') { return new JsonResponse($engine->statistics($query),200,['Cache-Control'=>'no-store']); }
                if ($mode==='errors' || $mode==='error_report') {
                    $report=(new ErrorAnalytics($engine->connection()))->report($query);
                    if ($mode==='error_report' && $query->format==='csv') {
                        $body=ErrorAnalytics::csv($report);
                        return new Response($body,200,['Content-Type'=>'text/csv; charset=utf-8','Cache-Control'=>'no-store',
                            'Content-Disposition'=>'attachment; filename="cajeerlogs-error-report.csv"',
                            'X-Content-SHA256'=>hash('sha256',$body)]);
                    }
                    return new JsonResponse($report,200,['Cache-Control'=>'no-store',
                        ...($mode==='error_report'?['Content-Disposition'=>'attachment; filename="cajeerlogs-error-report.json"']:[])]);
                }
                $page=$engine->explore($query,$signer,$state,$limit);
                if ($mode==='export') {
                    if (($match=$r->header('If-Match'))!==null && !hash_equals('"'.$page['page_digest'].'"',$match)) {
                        throw new HttpException(412,'page_changed','Page changed. Refresh the search before exporting.');
                    }
                    $headers=['Cache-Control'=>'no-store','X-Page-Cursor'=>$page['page_cursor'],'X-Page-Digest'=>$page['page_digest'],'Content-Disposition'=>'attachment; filename="cajeerlogs.'.$query->format.'"'];
                    if ($page['prev_cursor']!==null) { $headers['X-Prev-Cursor']=$page['prev_cursor']; }
                    if ($page['next_cursor']!==null) { $headers['X-Next-Cursor']=$page['next_cursor']; }
                    if ($query->format==='csv') {
                        $csv=LogExport::csv($page['items']);
                        if (strlen($csv)>5*1024*1024) throw new HttpException(413,'payload_too_large','CSV page exceeds the export budget. Reduce limit.');
                        return new Response($csv,200,['Content-Type'=>'text/csv; charset=utf-8']+$headers);
                    }
                    return new JsonResponse($page,200,$headers);
                }
                return new JsonResponse($page,200,['Cache-Control'=>'no-store']);
            },in_array($mode,['errors','error_report'],true));
            if (in_array($mode,['export','error_report'],true)) (new \Cajeer\Logs\Audit\AuditLogger($pdo))->record($mode==='error_report'?'errors.report_exported':'logs.exported',['request_id'=>$r->requestId,'project_id'=>$project,'source_id'=>$source,'format'=>$query->format],$p['id'],$project,null,$p['kind']==='token'?'token':'user');
            return $response;
        } catch (HttpException $e) {
            if ($e->status===403 && isset($p,$pdo)) (new \Cajeer\Logs\Audit\AuditLogger($pdo))->record('security.access_denied',['request_id'=>$r->requestId,'operation'=>'logs.'.$mode],$p['id'],null,'denied',$p['kind']==='token'?'token':'user');
            throw $e;
        } catch (DatabaseException $e) {
            throw new HttpException(503,'database_unavailable','Log storage unavailable.',['Retry-After'=>'5']);
        } catch (PDOException $e) {
            $state=(string)($e->errorInfo[0]??$e->getCode());
            if (DatabaseFailure::unavailable($e)||str_starts_with($state,'08')||str_starts_with($state,'53')||in_array($state,['42P01','42703','55P03','57014','57P01','57P02','57P03','40001','40P01','25006'],true)) { throw new HttpException(503,'database_unavailable','Log storage unavailable.',['Retry-After'=>'5']); }
            if (str_starts_with($state,'22')) { throw new HttpException(422,'invalid_query','Invalid query value.'); }
            throw $e;
        }
    }
}

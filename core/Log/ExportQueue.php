<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Audit\AuditLogger;
use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\FileDownloadResponse;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** PostgreSQL-backed export jobs. The session-bound advisory lock fences concurrent workers,
 * survives HTTP timeouts and is released automatically on connection loss. */
final class ExportQueue
{
    public const MAX_ROWS=1000000;
    public const MAX_BYTES=268435456;
    public const MAX_SECONDS=900;
    private const MAX_OUTSTANDING=5;
    private const TTL_HOURS=24;
    public function __construct(private readonly PDO $pdo,private readonly string $directory) {}

    private static function uuid(): string
    {
        $hex=bin2hex(random_bytes(16));
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);
    }
    private function statement(string $sql,array $params=[]): \PDOStatement
    {
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt;
    }
    private static function validFormat(string $format): string
    {
        if (!in_array($format,['json','csv'],true)) throw new HttpException(422,'invalid_payload','Export format must be json or csv.');
        return $format;
    }
    public static function filterInput(array $body,string $project): array
    {
        if (array_diff(array_keys($body),['format','filters'])!==[] || !isset($body['format'],$body['filters']) || !is_string($body['format']) || !is_array($body['filters']) || array_is_list($body['filters'])) throw new HttpException(422,'invalid_payload','Expected format and filters object.');
        self::validFormat($body['format']);
        if (array_intersect(array_keys($body['filters']),['project_id','cursor','limit','format','consistency','interval'])!==[]) throw new HttpException(422,'invalid_payload','Export filters cannot override project or pagination.');
        $query=LogQuery::parse(['project_id'=>$project]+$body['filters']+['limit'=>'100'],'export');
        if (!LogQuery::boundedRange($query->filters)) throw new HttpException(422,'invalid_query','Bulk exports require a time range of at most 31 days.');
        return [$query->filters,$body['format']];
    }
    public function create(string $project,string $user,array $filters,string $format): array
    {
        self::validFormat($format);
        $id=self::uuid();
        // Serialize quota checks across requests from the same owner.
        $this->statement("SELECT pg_advisory_xact_lock(hashtextextended(:key,0))",['key'=>'logs:export:'.$user])->closeCursor();
        $count=(int)$this->statement("SELECT count(*) FROM log_export_jobs WHERE requested_by=CAST(:user AS uuid) AND status IN ('queued','processing') AND expires_at>now()",['user'=>$user])->fetchColumn();
        if ($count>=self::MAX_OUTSTANDING) throw new HttpException(429,'export_queue_full','Too many pending exports.',['Retry-After'=>'30']);
        $this->statement("INSERT INTO log_export_jobs(id,project_id,requested_by,source_id,filters,format) VALUES(CAST(:id AS uuid),CAST(:project AS uuid),CAST(:user AS uuid),CAST(:source AS uuid),CAST(:filters AS jsonb),:format)",[
            'id'=>$id,'project'=>$project,'user'=>$user,'source'=>$filters['source_id']??null,'filters'=>json_encode($filters,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'format'=>$format,
        ])->closeCursor();
        return $this->get($project,$user,$id);
    }
    private static function publicRow(array $row): array
    {
        foreach (['row_count','byte_count','attempts'] as $k) $row[$k]=(int)$row[$k];
        return $row;
    }
    public function get(string $project,string $user,string $id): array
    {
        $row=$this->statement("SELECT id,project_id,requested_by,source_id,format,status,attempts,row_count,byte_count,checksum,error_code,created_at,started_at,finished_at,expires_at FROM log_export_jobs WHERE project_id=CAST(:project AS uuid) AND requested_by=CAST(:user AS uuid) AND id=CAST(:id AS uuid)",['project'=>$project,'user'=>$user,'id'=>LogQuery::uuid($id)])->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new HttpException(404,'not_found','Export not found.');
        if (strtotime($row['expires_at'])<=time() && $row['status']!=='expired') $row['status']='expired';
        return self::publicRow($row);
    }
    public function list(string $project,string $user,int $limit=30): array
    {
        if ($limit<1||$limit>100) throw new \InvalidArgumentException('Invalid list limit.');
        $rows=$this->statement('SELECT id,project_id,requested_by,source_id,format,status,attempts,row_count,byte_count,checksum,error_code,created_at,started_at,finished_at,expires_at FROM log_export_jobs WHERE project_id=CAST(:project AS uuid) AND requested_by=CAST(:user AS uuid) ORDER BY created_at DESC,id DESC LIMIT '.$limit,['project'=>$project,'user'=>$user])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { if (strtotime($row['expires_at'])<=time()) $row['status']='expired'; $row=self::publicRow($row); } unset($row);
        return $rows;
    }
    private function path(string $id,string $format): string { return $this->directory.'/'.$id.'.'.$format; }
    private function ensureDirectory(): void
    {
        $parts=explode('/',trim($this->directory,'/')); $path='/';
        foreach ($parts as $part) {
            $path=rtrim($path,'/').'/'.$part;
            if (is_link($path)) throw new \RuntimeException('Export directory contains a symlink.');
            if (!is_dir($path) && !mkdir($path,0700) && !is_dir($path)) throw new \RuntimeException('Cannot create export directory.');
        }
        $mode=fileperms($this->directory);
        if ($mode===false || ($mode&0077)!==0 || is_link($this->directory)) throw new \RuntimeException('Export directory must have private permissions (0700).');
        if (!is_writable($this->directory)) throw new \RuntimeException('Export directory is not writable.');
    }
    public function download(string $project,string $user,string $id): FileDownloadResponse
    {
        $job=$this->get($project,$user,$id);
        if ($job['status']!=='ready') throw new HttpException(409,'export_not_ready','Export is not ready for download.');
        $path=$this->path($job['id'],$job['format']);
        if (!is_string($job['checksum'])) {
            throw new HttpException(410,'export_integrity_failed','Export checksum is missing.');
        }
        // The response verifies and retains the same open descriptor it streams.
        return new FileDownloadResponse($path,'cajeerlogs-'.$job['id'].'.'.$job['format'],$job['format'],$job['byte_count'],$job['checksum']);
    }
    /** Claim a job under a session advisory lock that remains held until the result is committed. */
    private function claim(): ?array
    {
        return (new TransactionManager($this->pdo))->run(function () {
            $rows=$this->pdo->query("SELECT id FROM log_export_jobs WHERE status IN ('queued','processing') AND expires_at>now() AND attempts<3 ORDER BY CASE WHEN status='queued' THEN 0 ELSE 1 END,created_at,id LIMIT 100 FOR UPDATE SKIP LOCKED")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $id) {
                $key='logs:export:job:'.$id;
                $lock=$this->statement('SELECT pg_try_advisory_lock(hashtextextended(:key,0))',['key'=>$key])->fetchColumn();
                if (!in_array($lock,[true,'t','1',1],true)) continue;
                $job=$this->statement("UPDATE log_export_jobs SET status='processing', attempts=attempts+1,started_at=clock_timestamp(),error_code=NULL WHERE id=CAST(:id AS uuid) AND expires_at>now() RETURNING *",['id'=>$id])->fetch(PDO::FETCH_ASSOC);
                if ($job) return [$job,$key];
                $this->statement('SELECT pg_advisory_unlock(hashtextextended(:key,0))',['key'=>$key])->closeCursor();
            }
            // A process that died on its third attempt left a processing record. Only
            // finalize it when its session advisory lock is demonstrably free.
            $exhausted=$this->pdo->query("SELECT id FROM log_export_jobs WHERE status='processing' AND attempts>=3 AND expires_at>now() ORDER BY started_at LIMIT 50 FOR UPDATE SKIP LOCKED")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($exhausted as $id) {
                $key='logs:export:job:'.$id;
                $lock=$this->statement('SELECT pg_try_advisory_lock(hashtextextended(:key,0))',['key'=>$key])->fetchColumn();
                if (!in_array($lock,[true,'t','1',1],true)) continue;
                try {
                    $this->statement("UPDATE log_export_jobs SET status='failed',error_code='attempts_exhausted',finished_at=clock_timestamp() WHERE id=CAST(:id AS uuid) AND status='processing'",['id'=>$id])->closeCursor();
                } finally {
                    $this->statement('SELECT pg_advisory_unlock(hashtextextended(:key,0))',['key'=>$key])->closeCursor();
                }
            }
            return null;
        });
    }
    public function work(int $max=5): array
    {
        if ($max<1||$max>100) throw new \InvalidArgumentException('Export worker limit must be 1..100.');
        $report=['ready'=>0,'failed'=>0,'processed'=>0];
        for ($i=0;$i<$max;$i++) {
            $claim=$this->claim();
            if ($claim===null) break;
            [$job,$lock]=$claim;
            try {
                $this->process($job);
                ++$report['ready'];
            } catch (\Throwable $error) {
                // Do not persist SQL details, local paths, filter text or sensitive log content.
                $code=$error instanceof ExportBudgetExceeded?'limit_exceeded':($error instanceof \JsonException?'encoding_failed':'processing_failed');
                error_log('CajeerLogs export job failed: '.get_debug_type($error));
                $this->statement("UPDATE log_export_jobs SET status='failed',error_code=:error,finished_at=clock_timestamp() WHERE id=CAST(:id AS uuid) AND status='processing'",['error'=>$code,'id'=>$job['id']])->closeCursor();
                ++$report['failed'];
            } finally {
                $this->statement('SELECT pg_advisory_unlock(hashtextextended(:key,0))',['key'=>$lock])->closeCursor();
            }
            ++$report['processed'];
        }
        return $report;
    }
    /** Export uses a repeatable-read snapshot and an indexed keyset scan, not OFFSET. */
    private function process(array $job): void
    {
        $this->ensureDirectory();
        $id=$job['id']; $format=$job['format'];
        self::validFormat($format);
        $final=$this->path($id,$format);
        $temp=$this->directory.'/'.$id.'.'.bin2hex(random_bytes(8)).'.part';
        $stream=fopen($temp,'xb');
        if ($stream===false) throw new \RuntimeException('Cannot create temporary export.');
        @chmod($temp,0600);
        $rows=0; $started=microtime(true);
        try {
            $filters=json_decode($job['filters'],true,32,JSON_THROW_ON_ERROR);
            if (!is_array($filters)) throw new \RuntimeException('Invalid export filters.');
            $query=LogQuery::parse($filters+['limit'=>'100'],'export');
            if ($query->filters['project_id']!==$job['project_id'] || !LogQuery::boundedRange($query->filters)) throw new \RuntimeException('Export scope changed.');
            (new TransactionManager($this->pdo))->run(function () use ($query,$stream,$format,&$rows,$started) {
                // PostgreSQL can block inside a single page even if the PHP row loop is bounded.
                $this->pdo->exec("SET LOCAL statement_timeout='60000ms'");
                $this->pdo->exec("SET LOCAL lock_timeout='5000ms'");
                $this->write($stream,$format==='json'?"[\n":'');
                if ($format==='csv') LogExport::csvHeader($stream);
                foreach ((new LogRepository($this->pdo))->iterateBounded($query) as $item) {
                    if (++$rows>self::MAX_ROWS || microtime(true)-$started>self::MAX_SECONDS) throw new ExportBudgetExceeded('Export row or time limit reached.');
                    if ($format==='csv') LogExport::csvRow($stream,$item);
                    else $this->write($stream,($rows>1?",\n":'').json_encode($item,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                    if (ftell($stream)>self::MAX_BYTES) throw new ExportBudgetExceeded('Export size limit reached.');
                }
                if (microtime(true)-$started>self::MAX_SECONDS) throw new ExportBudgetExceeded('Export time limit reached.');
                if ($format==='json') $this->write($stream,"\n]\n");
            },isolation:'REPEATABLE READ',readOnly:true);
            if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) throw new \RuntimeException('Export flush failed.');
            $bytes=ftell($stream);
            if ($bytes===false || $bytes>self::MAX_BYTES) throw new ExportBudgetExceeded('Export size limit reached.');
            fclose($stream);$stream=null;
            $sha=hash_file('sha256',$temp);
            if (!is_string($sha)||!rename($temp,$final)) throw new \RuntimeException('Cannot finalize export artifact.');
            $updated=$this->statement("UPDATE log_export_jobs SET status='ready',row_count=:rows,byte_count=:bytes,checksum=:checksum,finished_at=clock_timestamp() WHERE id=CAST(:id AS uuid) AND status='processing' AND expires_at>now()",['id'=>$id,'rows'=>$rows,'bytes'=>$bytes,'checksum'=>$sha])->rowCount();
            if ($updated!==1) { @unlink($final); throw new \RuntimeException('Export expired during processing.'); }
            try {
                (new AuditLogger($this->pdo))->record('logs.export_completed',['export_id'=>$id,'format'=>$format,'rows'=>$rows,'bytes'=>$bytes,'sha256'=>$sha],$job['requested_by'],$job['project_id'],$id,'user');
            } catch (\Throwable) { error_log('CajeerLogs completed export audit unavailable'); }
        } finally {
            if (is_resource($stream)) fclose($stream);
            if (is_file($temp)) @unlink($temp);
        }
    }
    private function write($stream,string $data): void
    {
        for ($pos=0,$length=strlen($data);$pos<$length;$pos+=$n) {
            $n=fwrite($stream,substr($data,$pos));
            if ($n===false || $n===0) throw new \RuntimeException('Export write failed.');
        }
    }
    public function cleanup(int $limit=100): array
    {
        if ($limit<1||$limit>1000) throw new \InvalidArgumentException('Cleanup limit must be 1..1000.');
        $jobs=$this->statement('SELECT id,format FROM log_export_jobs WHERE expires_at<=now() AND status<>\'expired\' ORDER BY expires_at LIMIT '.$limit)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as $job) {
            $updated=$this->statement("UPDATE log_export_jobs SET status='expired',checksum=NULL WHERE id=CAST(:id AS uuid) AND expires_at<=now()",['id'=>$job['id']])->rowCount();
            if ($updated) { $file=$this->path($job['id'],$job['format']);if (is_file($file)&&!is_link($file)) @unlink($file); }
        }
        $this->statement("DELETE FROM log_export_jobs WHERE status='expired' AND expires_at<now()-interval '7 days'")->closeCursor();
        if (is_dir($this->directory)) {
            foreach (glob($this->directory.'/*.part')?:[] as $file) {
                if (!is_link($file)&&is_file($file)&&filemtime($file)<time()-86400) @unlink($file);
            }
        }
        return ['expired'=>count($jobs)];
    }
}

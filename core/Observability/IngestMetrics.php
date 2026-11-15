<?php
declare(strict_types=1);
namespace Cajeer\Logs\Observability;

use Cajeer\Logs\Http\Response;

/** Bounded, process-shared HTTP counters. Storage failure never changes a request result. */
final class IngestMetrics
{
    private const BUCKETS=[0.01,0.05,0.1,0.25,0.5,1,2.5,5,10,30,60];
    private const REASONS=['unauthorized','forbidden','authorization_changed','job_not_found','invalid_job','database_unavailable','https_required','invalid_header','invalid_content_length','invalid_body','ambiguous_body','invalid_transfer_encoding','unsupported_media_type','invalid_json','invalid_payload','payload_too_large','event_too_large','invalid_idempotency_key','idempotency_required','idempotency_conflict','rate_limited','project_archived','project_payload_limit','project_quota_exceeded','source_inactive','source_payload_limit','source_quota_exceeded','queue_full','redaction_failed','ingest_busy','service_unavailable','internal_error','invalid_query','search_timeout','not_found','totp_enrollment_required'];
    public function __construct(private readonly string $directory,private readonly string $group='ingest')
    {
        if (!in_array($group,['ingest','search'],true)) throw new \InvalidArgumentException('Invalid metrics group.');
    }
    private function modes(): array { return $this->group==='ingest'?['sync','async','job']:['list','stats','export','detail']; }
    public static function searchMode(string $method,string $path,string $prefix): ?string
    {
        if (!in_array($method,['GET','HEAD'],true)) return null;
        return match($path) {
            $prefix.'/logs'=>'list',$prefix.'/logs/stats'=>'stats',$prefix.'/logs/export'=>'export',
            default=>preg_match('~^'.preg_quote($prefix,'~').'/logs/[^/]+$~D',$path)?'detail':null,
        };
    }
    public static function mode(string $method,string $path,string $prefix): ?string
    {
        if ($method==='GET' && preg_match('~^'.preg_quote($prefix,'~').'/ingest/jobs/[^/]+$~D',$path)) return 'job';
        if ($method!=='POST') return null;
        return match($path) { $prefix.'/ingest'=>'sync',$prefix.'/ingest/async'=>'async',default=>null };
    }
    private function read(): array
    {
        $path=$this->directory.'/'.$this->group.'-metrics.json';
        if (!file_exists($path)) return [];
        if (is_link($path) || !is_file($path)) throw new \RuntimeException('Invalid metrics file.');
        $raw=file_get_contents($path,false,null,0,65537);
        if ($raw===false || strlen($raw)>65536) throw new \RuntimeException('Invalid metrics file.');
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid metrics state.');
        return $data;
    }
    public function record(string $mode,Response $response,float $seconds): void
    {
        if (!in_array($mode,$this->modes(),true)) return;
        $lock=null; $tmp=null;
        try {
            $path=$this->directory.'/'.$this->group.'-metrics.lock';
            if (is_link($path) || (file_exists($path) && !is_file($path))) return;
            $lock=@fopen($path,'c+b');
            if ($lock===false || !flock($lock,LOCK_EX)) return;
            $data=$this->read();
            $row=$data[$mode]??['statuses'=>[],'reasons'=>[],'count'=>0,'sum'=>0,'buckets'=>array_fill(0,count(self::BUCKETS),0)];
            $status=(string)$response->status;
            $row['statuses'][$status]=($row['statuses'][$status]??0)+1;
            if ($response->status>=400) {
                $reason=json_decode($response->body,true)['error']??'other';
                $reason=in_array($reason,self::REASONS,true)?$reason:'other';
                $row['reasons'][$reason]=($row['reasons'][$reason]??0)+1;
            }
            ++$row['count']; $row['sum']+=max(0,$seconds);
            foreach (self::BUCKETS as $i=>$bound) if ($seconds<=$bound) ++$row['buckets'][$i];
            $data[$mode]=$row;
            // One staging file under the same exclusive lock: interrupted writers do
            // not accumulate temporary files, and the published snapshot stays intact.
            $staging=$this->directory.'/'.$this->group.'-metrics.next';
            if (is_link($staging) || (file_exists($staging) && !is_file($staging))) return;
            $tmp=$staging;
            $raw=json_encode($data,JSON_THROW_ON_ERROR);
            if (strlen($raw)>65536 || file_put_contents($tmp,$raw)!==strlen($raw)) return;
            if (!chmod($tmp,0600)) return;
            rename($tmp,$this->directory.'/'.$this->group.'-metrics.json');
        } catch (\Throwable) { /* Metrics are not part of the durable ingest transaction. */ }
        finally {
            if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
            if (is_resource($lock)) fclose($lock);
        }
    }
    public function render(): string
    {
        try {
            if (!is_dir($this->directory) || !is_writable($this->directory)) throw new \RuntimeException('Metrics directory unavailable.');
            $data=$this->read();
            $out="# TYPE cajeerlogs_{$this->group}_metrics_available gauge\ncajeerlogs_{$this->group}_metrics_available 1\n# TYPE cajeerlogs_{$this->group}_requests_total counter\n# TYPE cajeerlogs_{$this->group}_rejections_total counter\n# TYPE cajeerlogs_{$this->group}_duration_seconds histogram\n";
            foreach ($this->modes() as $mode) {
                $row=$data[$mode]??null;
                if ($row===null) continue;
                $labels='mode="'.$mode.'"';
                foreach ($row['statuses'] as $status=>$count) if (preg_match('/^[2-5][0-9]{2}$/D',(string)$status)) $out.="cajeerlogs_{$this->group}_requests_total{{$labels},status=\"$status\"} ".(int)$count."\n";
                foreach ($row['reasons'] as $reason=>$count) if (in_array($reason,self::REASONS,true)||$reason==='other') $out.="cajeerlogs_{$this->group}_rejections_total{{$labels},reason=\"$reason\"} ".(int)$count."\n";
                foreach (self::BUCKETS as $i=>$bound) $out.="cajeerlogs_{$this->group}_duration_seconds_bucket{{$labels},le=\"$bound\"} ".(int)$row['buckets'][$i]."\n";
                $out.="cajeerlogs_{$this->group}_duration_seconds_bucket{{$labels},le=\"+Inf\"} ".(int)$row['count']."\ncajeerlogs_{$this->group}_duration_seconds_count{{$labels}} ".(int)$row['count']."\ncajeerlogs_{$this->group}_duration_seconds_sum{{$labels}} ".(float)$row['sum']."\n";
            }
            return $out;
        } catch (\Throwable) { return "# TYPE cajeerlogs_{$this->group}_metrics_available gauge\ncajeerlogs_{$this->group}_metrics_available 0\n"; }
    }
}

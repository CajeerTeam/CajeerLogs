<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use Cajeer\Logs\Log\{LogQuery,LogRepository};
use PDO;

/** PostgreSQL-native diagnostics. Statistics are estimates/counters, not exact row counts. */
final class PerformanceInspector
{
    public function __construct(private readonly PDO $pdo) {}

    public function report(): array
    {
        $tables=$this->pdo->query(<<<'SQL'
SELECT s.relname AS relation, pg_relation_size(s.relid) AS heap_bytes,
       pg_indexes_size(s.relid) AS index_bytes, pg_total_relation_size(s.relid) AS total_bytes,
       s.n_live_tup AS estimated_live_rows,s.n_dead_tup AS estimated_dead_rows,
       s.n_mod_since_analyze,s.seq_scan,s.idx_scan,s.last_autovacuum,s.last_autoanalyze,
       s.last_vacuum,s.last_analyze,c.reloptions::text AS relation_options,
       c.reltuples::bigint AS planner_rows, age(c.relfrozenxid) AS xid_age
FROM pg_stat_user_tables s JOIN pg_class c ON c.oid=s.relid
WHERE s.schemaname=current_schema() ORDER BY total_bytes DESC,s.relname
SQL)->fetchAll(PDO::FETCH_ASSOC);
        $indexes=$this->pdo->query(<<<'SQL'
SELECT s.relname AS relation,s.indexrelname AS index,pg_relation_size(s.indexrelid) AS bytes,
       s.idx_scan,s.idx_tup_read,s.idx_tup_fetch,i.indisvalid AS valid,i.indisready AS ready
FROM pg_stat_user_indexes s JOIN pg_index i ON i.indexrelid=s.indexrelid
WHERE s.schemaname=current_schema() ORDER BY bytes DESC,s.indexrelname
SQL)->fetchAll(PDO::FETCH_ASSOC);
        $db=$this->pdo->query("SELECT stats_reset,blks_read,blks_hit,temp_files,temp_bytes,deadlocks,blk_read_time,blk_write_time FROM pg_stat_database WHERE datname=current_database()")->fetch(PDO::FETCH_ASSOC);
        $settings=$this->pdo->query("SELECT name,setting,unit FROM pg_settings WHERE name IN ('autovacuum','track_io_timing','shared_buffers','work_mem','maintenance_work_mem','max_connections','autovacuum_vacuum_scale_factor','autovacuum_analyze_scale_factor','autovacuum_freeze_max_age') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $activity=$this->pdo->query("SELECT state, count(*) AS sessions, count(*) FILTER (WHERE wait_event_type='Lock') AS waiting_for_lock, max(extract(epoch FROM statement_timestamp()-query_start)) FILTER (WHERE state='active') AS oldest_active_seconds FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() GROUP BY state ORDER BY state")->fetchAll(PDO::FETCH_ASSOC);
        return ['tables'=>$tables,'indexes'=>$indexes,'database'=>$db,'settings'=>$settings,'activity'=>$activity,'notes'=>['Table tuple counts are estimates; dead rows are not measured bloat.','Counters are cumulative since reset; zero index scans do not justify dropping an index.']];
    }

    public static function queries(string $project,?string $trace=null,?array $filters=null): array
    {
        $first=['d'=>'older','t'=>null,'id'=>null,'w'=>null,'exp'=>0];
        $queries=['latest'=>LogRepository::explorerStatement(LogQuery::parse(['project_id'=>$project]),$first)];
        if ($trace!==null) $queries['trace']=LogRepository::explorerStatement(LogQuery::parse(['project_id'=>$project,'trace_id'=>$trace]),$first);
        if ($filters!==null) {
            if (array_key_exists('project_id',$filters)) throw new DatabaseException('The project must be specified only as the first argument.');
            $query=LogQuery::parse(['project_id'=>$project]+$filters);
            if ($query->cursor!==null || $query->consistency!=='live') throw new DatabaseException('EXPLAIN accepts first-page live filters only.');
            $queries['filtered']=LogRepository::explorerStatement($query,$first);
        }
        $queries['retention_candidates']=["SELECT l.id,l.received_at FROM projects p JOIN LATERAL (SELECT id,received_at FROM log_events WHERE project_id=p.id AND received_at<statement_timestamp()-p.retention_days*interval '1 day' ORDER BY received_at,id LIMIT 1000) l ON true WHERE p.id=CAST(:project AS uuid) AND p.retention_days IS NOT NULL",['project'=>$project]];
        $queries['queue_ready']=["SELECT id FROM jobs WHERE queue='ingest-v1' AND status='pending' AND available_at<=statement_timestamp() ORDER BY available_at,id LIMIT 100",[]];
        return $queries;
    }
    public function explain(string $project,?string $trace=null,bool $analyze=false,?array $filters=null): array
    {
        $project=LogQuery::uuid($project);
        if ($trace!==null && ($trace==='' || strlen($trace)>128 || str_contains($trace,"\0") || !preg_match('//u',$trace))) throw new DatabaseException('Invalid trace identifier.');
        return (new TransactionManager($this->pdo))->run(function () use ($project,$trace,$analyze,$filters) {
            $plans=[];
            foreach (self::queries($project,$trace,$filters) as $name=>[$sql,$params]) {
                $q=$this->pdo->prepare('EXPLAIN ('.($analyze?'ANALYZE, BUFFERS, ':'').'FORMAT JSON) '.$sql);
                $q->execute($params); $plans[$name]=json_decode((string)$q->fetchColumn(),true,128,JSON_THROW_ON_ERROR);$q->closeCursor();
            }
            return ['analyze'=>$analyze,'plans'=>$plans];
        },isolation:'REPEATABLE READ',readOnly:true);
    }
}

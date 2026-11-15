<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database;

use PDO;

/** Read-only logical audit. Samples identifiers only; never returns credentials or payloads. */
final class IntegrityInspector
{
    public function __construct(private readonly PDO $pdo) {}

    /** SQL is static, no caller-controlled identifiers. Missing retained events are not corruption. */
    public static function checks(): array
    {
        return [
            'log_source_scope'=>"SELECT l.id::text AS id FROM log_events l LEFT JOIN sources s ON s.id=l.source_id WHERE l.source_id IS NOT NULL AND (s.id IS NULL OR s.project_id IS DISTINCT FROM l.project_id OR s.slug IS DISTINCT FROM l.source)",
            'log_token_scope'=>"SELECT l.id::text AS id FROM log_events l LEFT JOIN api_tokens t ON t.id=l.ingest_token_id WHERE l.ingest_token_id IS NOT NULL AND (t.id IS NULL OR l.project_id IS NULL OR t.project_id IS DISTINCT FROM l.project_id OR (t.source_id IS NOT NULL AND t.source_id IS DISTINCT FROM l.source_id) OR NOT (t.scopes @> '[\"logs:write\"]'::jsonb))",
            'job_token_scope'=>"SELECT j.id::text AS id FROM jobs j LEFT JOIN api_tokens t ON t.id=j.token_id WHERE j.queue='ingest-v1' AND (t.id IS NULL OR t.project_id IS DISTINCT FROM j.project_id OR NOT (t.scopes @> '[\"logs:write\"]'::jsonb))",
            'job_lease_state'=>"SELECT id::text FROM jobs WHERE queue='ingest-v1' AND NOT ((status='running' AND lease_id IS NOT NULL AND lease_until IS NOT NULL AND reserved_at IS NOT NULL) OR (status<>'running' AND lease_id IS NULL AND lease_until IS NULL AND reserved_at IS NULL))",
            'job_completion_state'=>"SELECT id::text FROM jobs WHERE queue='ingest-v1' AND ((status IN ('completed','dead','cancelled')) IS DISTINCT FROM (completed_at IS NOT NULL))",
            'job_attempt_state'=>"SELECT j.id::text FROM jobs j WHERE j.queue='ingest-v1' AND (j.attempts<0 OR j.attempts>j.max_attempts OR (j.status='running' AND NOT EXISTS (SELECT 1 FROM job_attempts a WHERE a.job_id=j.id AND a.attempt=j.attempts AND a.outcome='running' AND a.finished_at IS NULL)) OR EXISTS (SELECT 1 FROM job_attempts a WHERE a.job_id=j.id AND (a.attempt>j.attempts OR (a.outcome='running' AND (j.status<>'running' OR a.attempt<>j.attempts OR a.finished_at IS NOT NULL)) OR (a.outcome<>'running' AND a.finished_at IS NULL))))",
            'job_payload_shape'=>"SELECT id::text FROM jobs WHERE queue='ingest-v1' AND status IN ('pending','running','dead') AND (jsonb_typeof(payload->'events') IS DISTINCT FROM 'array' OR COALESCE(jsonb_typeof(payload->'sources'),'') NOT IN ('object','array') OR CASE WHEN jsonb_typeof(payload->'events')='array' THEN jsonb_array_length(payload->'events') NOT BETWEEN 1 AND 100 ELSE true END)",
            'job_result_shape'=>"SELECT id::text FROM jobs WHERE queue='ingest-v1' AND status='completed' AND (jsonb_typeof(result->'event_ids') IS DISTINCT FROM 'array' OR CASE WHEN jsonb_typeof(result->'event_ids')='array' THEN jsonb_array_length(result->'event_ids') NOT BETWEEN 1 AND 100 OR result->'stored' IS DISTINCT FROM to_jsonb(jsonb_array_length(result->'event_ids')) OR EXISTS (SELECT 1 FROM jsonb_array_elements(result->'event_ids') e WHERE jsonb_typeof(e)<>'string' OR (e#>>'{}') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') OR (SELECT count(DISTINCT e) FROM jsonb_array_elements(result->'event_ids') e)<>jsonb_array_length(result->'event_ids') ELSE true END)",
            'receipt_shape'=>"SELECT token_id::text FROM ingest_receipts WHERE CASE WHEN jsonb_typeof(event_ids)='array' THEN jsonb_array_length(event_ids) NOT BETWEEN 1 AND 100 OR EXISTS (SELECT 1 FROM jsonb_array_elements(event_ids) e WHERE jsonb_typeof(e)<>'string' OR (e#>>'{}') !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') OR (SELECT count(DISTINCT e) FROM jsonb_array_elements(event_ids) e)<>jsonb_array_length(event_ids) ELSE true END",
            'receipt_event_scope'=>"SELECT r.token_id::text FROM ingest_receipts r JOIN LATERAL jsonb_array_elements_text(CASE WHEN jsonb_typeof(r.event_ids)='array' THEN r.event_ids ELSE '[]'::jsonb END) e(id) ON true JOIN log_events l ON l.id=CASE WHEN e.id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN e.id::uuid END WHERE l.ingest_token_id IS DISTINCT FROM r.token_id GROUP BY r.token_id,r.key_hash",
            'job_event_scope'=>"SELECT j.id::text FROM jobs j JOIN LATERAL jsonb_array_elements_text(CASE WHEN jsonb_typeof(j.result->'event_ids')='array' THEN j.result->'event_ids' ELSE '[]'::jsonb END) e(id) ON true JOIN log_events l ON l.id=CASE WHEN e.id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN e.id::uuid END WHERE j.queue='ingest-v1' AND j.status='completed' AND (l.ingest_token_id IS DISTINCT FROM j.token_id OR l.project_id IS DISTINCT FROM j.project_id) GROUP BY j.id",
            'job_payload_source_scope'=>"SELECT j.id::text FROM jobs j JOIN api_tokens t ON t.id=j.token_id WHERE j.queue='ingest-v1' AND j.status IN ('pending','running','dead') AND EXISTS (SELECT 1 FROM jsonb_array_elements(CASE WHEN jsonb_typeof(j.payload->'events')='array' THEN j.payload->'events' ELSE '[]'::jsonb END) e WHERE NOT EXISTS (SELECT 1 FROM sources s WHERE s.project_id=j.project_id AND s.slug=e->>'source' AND s.id::text=CASE WHEN jsonb_typeof(j.payload->'sources')='object' THEN j.payload->'sources'->>(e->>'source') WHEN jsonb_typeof(j.payload->'sources')='array' AND (e->>'source') ~ '^(0|[1-9][0-9]{0,8})$' THEN j.payload->'sources'->>((e->>'source')::integer) END AND (t.source_id IS NULL OR t.source_id=s.id)))",
            'webhook_payload_scope'=>"SELECT d.id::text FROM webhook_deliveries d JOIN integration_webhooks h ON h.id=d.webhook_id WHERE d.payload->>'event'='logs.ingested' AND (d.payload->>'project_id' IS DISTINCT FROM h.project_id::text OR jsonb_typeof(d.payload->'event_ids') IS DISTINCT FROM 'array' OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(d.payload->'event_ids')='array' THEN d.payload->'event_ids' ELSE '[]'::jsonb END) e(id) JOIN log_events l ON l.id=CASE WHEN e.id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN e.id::uuid END WHERE l.project_id IS DISTINCT FROM h.project_id))",
            'receipt_job_overlap'=>"SELECT j.id::text FROM jobs j JOIN ingest_receipts r ON r.token_id=j.token_id AND r.key_hash=j.key_hash WHERE j.queue='ingest-v1' AND r.expires_at>statement_timestamp()",
            'rotation_scope'=>"SELECT t.id::text FROM api_tokens t LEFT JOIN api_tokens n ON n.id=t.rotated_to_id WHERE t.rotated_to_id IS NOT NULL AND (n.id IS NULL OR n.project_id IS DISTINCT FROM t.project_id OR n.source_id IS DISTINCT FROM t.source_id OR n.service_account_id IS DISTINCT FROM t.service_account_id OR NOT (t.scopes @> n.scopes) OR n.created_at<t.created_at)",
            'webhook_attempt_state'=>"SELECT d.id::text FROM webhook_deliveries d WHERE (d.status='sending' AND NOT EXISTS (SELECT 1 FROM webhook_attempts a WHERE a.delivery_id=d.id AND a.attempt=d.attempts AND a.outcome='sending' AND a.finished_at IS NULL)) OR EXISTS (SELECT 1 FROM webhook_attempts a WHERE a.delivery_id=d.id AND (a.attempt>d.attempts OR (a.outcome='sending' AND (d.status<>'sending' OR a.attempt<>d.attempts OR a.finished_at IS NOT NULL)) OR (a.outcome<>'sending' AND a.finished_at IS NULL)))",
        ];
    }
    public static function deliveryChecks(): array
    {
        return [
            'delivery_family'=>"SELECT t.id::text FROM api_tokens t LEFT JOIN api_tokens p ON p.rotated_to_id=t.id LEFT JOIN api_tokens root ON root.id=t.delivery_family_id WHERE (p.id IS NULL AND t.delivery_family_id IS NOT NULL) OR (p.id IS NOT NULL AND t.delivery_family_id IS DISTINCT FROM COALESCE(p.delivery_family_id,p.id)) OR (root.id IS NOT NULL AND (root.delivery_family_id IS NOT NULL OR root.project_id IS DISTINCT FROM t.project_id OR root.source_id IS DISTINCT FROM t.source_id OR root.service_account_id IS DISTINCT FROM t.service_account_id))",
            'delivery_key_overlap'=>"WITH deliveries AS (SELECT COALESCE(t.delivery_family_id,t.id) AS family,r.key_hash,r.delivery_digest FROM ingest_receipts r JOIN api_tokens t ON t.id=r.token_id WHERE r.expires_at>statement_timestamp() UNION ALL SELECT COALESCE(t.delivery_family_id,t.id),j.key_hash,j.delivery_digest FROM jobs j JOIN api_tokens t ON t.id=j.token_id WHERE j.queue='ingest-v1') SELECT family::text FROM deliveries GROUP BY family,key_hash HAVING count(*)>1 AND bool_or(delivery_digest IS NOT NULL)",
            'delivery_job_count'=>"SELECT id::text FROM jobs WHERE delivery_count IS NOT NULL AND ((status IN ('pending','running','dead') AND CASE WHEN jsonb_typeof(payload->'events')='array' THEN jsonb_array_length(payload->'events') IS DISTINCT FROM delivery_count ELSE true END) OR (status='completed' AND CASE WHEN jsonb_typeof(result->'event_ids')='array' THEN jsonb_array_length(result->'event_ids') IS DISTINCT FROM delivery_count ELSE true END))",
        ];
    }
    public function inspect(int $limit=20): array
    {
        if ($limit<1 || $limit>100) throw new DatabaseException('Integrity sample limit must be 1..100.');
        return (new TransactionManager($this->pdo))->run(function () use ($limit) {
            $checks=[]; $ok=true;
            $queries=self::checks();
            // Older verified schemas can still run the pre-migration integrity audit.
            if ($this->pdo->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='api_tokens' AND column_name='delivery_family_id')")->fetchColumn()===true) $queries+=self::deliveryChecks();
            foreach ($queries as $name=>$sql) {
                $rows=$this->pdo->query($sql.' LIMIT '.($limit+1))->fetchAll(PDO::FETCH_COLUMN);
                $more=count($rows)>$limit; if ($more) array_pop($rows);
                if ($rows!==[]) $ok=false;
                $checks[$name]=['sample_ids'=>$rows,'truncated'=>$more,'issues_at_least'=>count($rows)+(int)$more];
            }
            $legacy=$this->pdo->query('SELECT count(*) FROM log_events WHERE source_id IS NULL')->fetchColumn();
            return ['status'=>$ok?'ok':'issues','checks'=>$checks,'legacy_unassigned'=>(string)$legacy,'read_only'=>true];
        },isolation:'REPEATABLE READ',readOnly:true);
    }
}

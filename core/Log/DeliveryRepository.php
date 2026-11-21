<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\HttpException;
use Cajeer\Logs\Security\IngestPrincipal;
use PDO;

/** Unified delivery lookup, serialized with project admission. No raw request body is stored. */
final class DeliveryRepository
{
    public const RETENTION_SECONDS=604800;
    public static function retention(int $seconds): int
    {
        if ($seconds<86400 || $seconds>2592000) throw new \InvalidArgumentException('Invalid delivery retention.');
        return $seconds;
    }
    public function __construct(private readonly PDO $pdo) {}
    private function sql(string $sql,array $params): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }

    /** The authenticated token and project must be locked before this lookup. The
     * project lock serializes both modes, including simultaneous successor requests. */
    public function namespace(IngestPrincipal $p): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Delivery lookup requires transaction.');
        $row=$this->sql(<<<'SQL'
            SELECT root.id,root.token_hash FROM api_tokens t JOIN api_tokens root ON root.id=COALESCE(t.delivery_family_id,t.id)
            WHERE t.id=:token AND t.project_id=:project AND root.delivery_family_id IS NULL
                AND root.project_id=t.project_id AND root.source_id IS NOT DISTINCT FROM t.source_id
                AND root.service_account_id IS NOT DISTINCT FROM t.service_account_id
            SQL,['token'=>$p->id,'project'=>$p->projectId])->fetch();
        if (!$row || !preg_match('/^[a-f0-9]{64}$/D',$row['token_hash'])) throw new DatabaseException('Invalid delivery namespace.');
        return $row;
    }
    public static function digest(string $body,string $rootHash,string $master): string
    {
        // A configured master prevents offline body guessing from a database-only dump.
        // Unconfigured development installations use the credential digest as a stable salt.
        $key=\Cajeer\Logs\Security\TwoFactorManager::keyValid($master)
            ? hash_hkdf('sha256',hex2bin($master),32,'cajeerlogs:delivery:v1',$rootHash) : $rootHash;
        return hash_hmac('sha256',"cajeerlogs-delivery-v1\0".$body,$key);
    }
    public static function legacyCount(string $body): int
    {
        // Exact bytes already authenticated against the legacy HMAC. Do not apply
        // newer event rules merely to recover a cancelled historic job count.
        try { $decoded=json_decode($body,false,64,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new DatabaseException('Invalid legacy delivery body.'); }
        $count=$decoded instanceof \stdClass && property_exists($decoded,'events') && is_array($decoded->events) ? count($decoded->events) : ($decoded instanceof \stdClass ? 1 : 0);
        if ($count<1 || $count>100) throw new DatabaseException('Invalid legacy delivery count.');
        return $count;
    }
    private static function conflict(): never
    {
        throw new HttpException(409,'idempotency_conflict','Idempotency-Key already belongs to a different delivery or a legacy credential.');
    }
    /** New deliveries can be replayed by the same rotation family; old HMAC hashes
     * cannot be verified by a different bearer, so fail closed instead of duplicating. */
    public function lookup(IngestPrincipal $p,string $family,string $key,string $digest,string $legacyHash,bool $async): ?array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Delivery lookup requires transaction.');
        // FOR SHARE on projects allows concurrent members of the same rotated-token
        // family. Serialize only the same family/key before looking up a receipt or
        // job; otherwise two different tokens can both observe an absent delivery
        // and commit two distinct records. Held through commit/rollback by PostgreSQL.
        // This never locks the projects or api_tokens rows and cannot invert their
        // foreign-key lock order with an async worker.
        $this->sql(<<<'SQL'
            SELECT pg_advisory_xact_lock(hashtextextended(
                'cajeerlogs:delivery:' || current_schema() || ':' || CAST(:project AS text)
                || ':' || CAST(:family AS text) || ':' || CAST(:key AS text), 0))
            SQL,['project'=>$p->projectId,'family'=>$family,'key'=>$key])->fetchColumn();
        $rows=$this->sql(<<<'SQL'
            WITH members AS MATERIALIZED (
                SELECT t.id FROM api_tokens t JOIN api_tokens root ON root.id=CAST(:root AS uuid)
                WHERE (t.id=root.id OR t.delivery_family_id=CAST(:family AS uuid))
                AND t.project_id=root.project_id AND t.source_id IS NOT DISTINCT FROM root.source_id
                AND t.service_account_id IS NOT DISTINCT FROM root.service_account_id
            )
            SELECT 'sync' AS mode,r.token_id,r.request_hash,r.delivery_digest,r.event_ids::text AS ids,NULL::text AS job_id,NULL::text AS status,NULL::integer AS delivery_count
            FROM members m JOIN ingest_receipts r ON r.token_id=m.id WHERE r.key_hash=:receipt AND r.expires_at>clock_timestamp()
            UNION ALL
            SELECT 'async',j.token_id,j.request_hash,j.delivery_digest,NULL::text,j.id::text,j.status,COALESCE(j.delivery_count, CASE WHEN jsonb_typeof(j.result->'event_ids')='array' THEN jsonb_array_length(j.result->'event_ids') WHEN jsonb_typeof(j.payload->'events')='array' THEN jsonb_array_length(j.payload->'events') END)
            FROM members m JOIN jobs j ON j.token_id=m.id WHERE j.queue='ingest-v1' AND j.key_hash=:job AND j.project_id=:project
            LIMIT 2
            SQL,['root'=>$family,'family'=>$family,'receipt'=>$key,'job'=>$key,'project'=>$p->projectId])->fetchAll();
        if (!$rows) return null;
        if (count($rows)!==1) self::conflict(); // historic collisions cannot be reconciled safely
        $row=$rows[0];
        if ($row['mode']!==($async?'async':'sync')) self::conflict();
        if ($row['delivery_digest']!==null) {
            if (!hash_equals($row['delivery_digest'],$digest)) self::conflict();
        } elseif ($row['token_id']!==$p->id || !hash_equals($row['request_hash'],$legacyHash)) self::conflict();
        if ($async && $row['delivery_count']!==null && ((int)$row['delivery_count']<1 || (int)$row['delivery_count']>100)) throw new DatabaseException('Invalid stored delivery count.');
        if ($async) return ['job_id'=>$row['job_id'],'status'=>$row['status'],'replayed'=>true,'received'=>$row['delivery_count']===null?null:(int)$row['delivery_count']];
        $ids=json_decode($row['ids'],true,32,JSON_THROW_ON_ERROR);
        LogRepository::validateReceiptIds($ids);
        return ['event_ids'=>$ids,'received'=>count($ids)];
    }

    /** Only v0.2.7+ jobs are shared across rotation; historic jobs retain exact ownership. */
    public function jobOwner(IngestPrincipal $p,string $id): ?string
    {
        $family=$this->namespace($p)['id'];
        $owner=$this->sql(<<<'SQL'
            SELECT j.token_id FROM jobs j JOIN api_tokens t ON t.id=j.token_id
            WHERE j.id=:id AND j.queue='ingest-v1' AND j.project_id=:project
            AND (j.token_id=:token OR (j.delivery_digest IS NOT NULL AND COALESCE(t.delivery_family_id,t.id)=:family
                AND t.project_id=j.project_id AND t.source_id IS NOT DISTINCT FROM CAST(:source AS uuid)
                AND t.service_account_id IS NOT DISTINCT FROM CAST(:account AS uuid)))
            SQL,['id'=>$id,'project'=>$p->projectId,'token'=>$p->id,'family'=>$family,'source'=>$p->sourceId,'account'=>$p->serviceAccountId])->fetchColumn();
        return is_string($owner)?$owner:null;
    }
}

<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use PDO;
use Cajeer\Logs\Http\HttpException;

final class LogRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param list<IngestEvent> $events @param array<string,string> $sources @return list<string> */
    public function storeBatch(array $events, string $project, array $sources, string $token): array
    {
        if (!$this->pdo->inTransaction() || $events===[]) { throw new \LogicException('Log writes require a nonempty transaction.'); }
        // Generate UUIDs before a single multi-row INSERT, preserving receipt order.
        $ids=[];$rows=[];
        foreach ($events as $event) {
            $bytes=random_bytes(16);
            $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
            $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
            $hex=bin2hex($bytes);
            $id=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $ids[]=$id;
            $rows[]=['id'=>$id,'occurred_at'=>$event->timestamp,'source'=>$event->source,'service'=>$event->service,'host'=>$event->host,
                'level'=>$event->level,'message'=>$event->message,'context'=>$event->context,'trace_id'=>$event->traceId,'request_id'=>$event->requestId,
                'tags'=>$event->tags,'source_id'=>$sources[$event->source]];
        }
        $stmt=$this->pdo->prepare(<<<'SQL'
            INSERT INTO log_events(id,occurred_at,source,service,host,level,message,context,trace_id,request_id,tags,project_id,source_id,ingest_token_id)
            SELECT e.id,e.occurred_at,e.source,e.service,e.host,e.level,e.message,e.context,e.trace_id,e.request_id,
                ARRAY(SELECT jsonb_array_elements_text(e.tags)),CAST(:project AS uuid),e.source_id,CAST(:token AS uuid)
            FROM jsonb_to_recordset(CAST(:events AS jsonb)) AS e(id uuid,occurred_at timestamptz,source text,service text,host text,
                level text,message text,context jsonb,trace_id text,request_id text,tags jsonb,source_id uuid)
            SQL);
        $stmt->execute(['events'=>json_encode($rows,JSON_THROW_ON_ERROR),'project'=>$project,'token'=>$token]);
        if ($stmt->rowCount()!==count($ids)) { throw new \RuntimeException('Incomplete batch insert.'); }
        return $ids;
    }

    /** Called inside the same transaction as log storage; the authenticated token row is locked. */
    public function claimNonce(string $token,string $nonce): void
    {
        $params=['token'=>$token,'nonce'=>hash('sha256',$nonce)];
        $this->sql('DELETE FROM ingest_nonces WHERE token_id=:token AND nonce_hash=:nonce AND expires_at<=clock_timestamp()',$params);
        $s=$this->sql("INSERT INTO ingest_nonces(token_id,nonce_hash,expires_at) VALUES (:token,:nonce,clock_timestamp()+interval '11 minutes') ON CONFLICT DO NOTHING",$params);
        if ($s->rowCount()!==1) { throw new HttpException(409,'replay_detected','Signature nonce has already been used.'); }
    }

    /** @return list<string>|null */
    public function receipt(string $token,string $keyHash,string $requestHash): ?array
    {
        $params=['token'=>$token,'key'=>$keyHash];
        $this->sql('DELETE FROM ingest_receipts WHERE token_id=:token AND key_hash=:key AND expires_at<=clock_timestamp()',$params);
        $row=$this->sql('SELECT request_hash,event_ids FROM ingest_receipts WHERE token_id=:token AND key_hash=:key',$params)->fetch();
        if (!$row) { return null; }
        if (!hash_equals($row['request_hash'],$requestHash)) { throw new HttpException(409,'idempotency_conflict','Idempotency-Key was used for a different request.'); }
        return json_decode($row['event_ids'],true,32,JSON_THROW_ON_ERROR);
    }

    public function saveReceipt(string $token,string $keyHash,string $requestHash,array $ids): void
    {
        $this->sql("INSERT INTO ingest_receipts(token_id,key_hash,request_hash,event_ids,expires_at) VALUES (:token,:key,:hash,CAST(:ids AS jsonb),clock_timestamp()+interval '24 hours')",
            ['token'=>$token,'key'=>$keyHash,'hash'=>$requestHash,'ids'=>json_encode($ids,JSON_THROW_ON_ERROR)]);
    }

    private function sql(string $sql,array $params): \PDOStatement { $stmt=$this->pdo->prepare($sql); $stmt->execute($params); return $stmt; }
}

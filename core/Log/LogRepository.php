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
        $stmt=$this->pdo->prepare(<<<'SQL'
            INSERT INTO log_events(occurred_at,source,service,host,level,message,context,trace_id,request_id,tags,project_id,source_id,ingest_token_id)
            VALUES (CAST(:occurred AS timestamptz),:source,:service,:host,:level,:message,CAST(:context AS jsonb),:trace,:request,
                ARRAY(SELECT jsonb_array_elements_text(CAST(:tags AS jsonb))),:project,:source_id,:token)
            RETURNING id
            SQL);
        $ids=[];
        foreach ($events as $event) {
            $stmt->execute(['occurred'=>$event->timestamp,'source'=>$event->source,'service'=>$event->service,'host'=>$event->host,
                'level'=>$event->level,'message'=>$event->message,'context'=>json_encode($event->context,JSON_THROW_ON_ERROR),
                'trace'=>$event->traceId,'request'=>$event->requestId,'tags'=>json_encode($event->tags,JSON_THROW_ON_ERROR),
                'project'=>$project,'source_id'=>$sources[$event->source],'token'=>$token]);
            $id=$stmt->fetchColumn(); $stmt->closeCursor();
            if (!is_string($id)) { throw new \RuntimeException('Missing stored event ID.'); }
            $ids[]=$id;
        }
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

<?php
declare(strict_types=1);
namespace Cajeer\Logs\Audit;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo) {}
    public function record(string $action, array $context = [], ?string $actorId = null, ?string $target = null, ?string $result = null, ?string $actorType = null): void
    {
        // Callers supply explicit metadata, never the request body, credentials or log payload.
        $result ??= in_array($action,['auth.login_failed','auth.reauthentication_failed'],true)?'failure':'success';
        $actorType ??= $actorId!==null?'user':($action==='auth.login_failed'?'anonymous':'system');
        $stmt = $this->pdo->prepare('INSERT INTO audit_log(actor_id,actor_type,action,target,result,context) VALUES (:actor,:kind,:action,:target,:result,CAST(:context AS jsonb))');
        try {
            $stmt->execute(['actor'=>$actorId,'kind'=>$actorType,'action'=>$action,'target'=>$target,'result'=>$result,'context'=>json_encode((object)$context,JSON_THROW_ON_ERROR)]);
        } catch (\Throwable $e) {
            // Do not log SQL, exception text or context: these can contain private metadata.
            error_log('CajeerLogs audit_write_failed');
            throw $e;
        }
    }

    /** Verify a consistent chain prefix. An independently retained checkpoint detects tail deletion. */
    public function verify(?int $checkpointPosition=null, ?string $checkpointHash=null): array
    {
        if (($checkpointPosition===null)!==($checkpointHash===null) || ($checkpointPosition!==null && ($checkpointPosition<0 || !preg_match('/^[a-f0-9]{64}$/D',$checkpointHash)))) throw new \InvalidArgumentException('Invalid audit checkpoint.');
        $sql=<<<'SQL'
        WITH chain AS (
          SELECT chain_position,event_hash,previous_hash,
            row_number() OVER (ORDER BY chain_position) AS expected_position,
            lag(event_hash,1,repeat('0',64)) OVER (ORDER BY chain_position) AS expected_previous,
            audit_digest(chain_position,previous_hash,id,actor_id,actor_type,action,target,project_id,result,context,created_at) AS expected_hash
          FROM audit_log
        ) SELECT count(*) AS entries, count(*) FILTER (WHERE chain_position<>expected_position OR previous_hash<>expected_previous OR event_hash<>expected_hash) AS invalid,
          coalesce(max(chain_position),0) AS head_position,
          coalesce((SELECT event_hash FROM chain ORDER BY chain_position DESC LIMIT 1),repeat('0',64)) AS head_hash,
          (SELECT event_hash FROM chain WHERE chain_position=:checkpoint) AS checkpoint_hash FROM chain
        SQL;
        $stmt=$this->pdo->prepare($sql); $stmt->execute(['checkpoint'=>$checkpointPosition]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        $match=$checkpointPosition===null || ($checkpointPosition===0?hash_equals(str_repeat('0',64),$checkpointHash):is_string($row['checkpoint_hash'])&&hash_equals($checkpointHash,$row['checkpoint_hash']));
        return ['valid'=>(int)$row['invalid']===0 && $match,'entries'=>(int)$row['entries'],'invalid'=>(int)$row['invalid'],'head_position'=>(int)$row['head_position'],'head_hash'=>$row['head_hash'],'checkpoint_matches'=>$match];
    }
}

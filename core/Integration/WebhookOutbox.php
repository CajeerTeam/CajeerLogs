<?php
declare(strict_types=1);
namespace Cajeer\Logs\Integration;

use PDO;

final class WebhookOutbox
{
    private const MAX_ATTEMPTS=8;
    public function __construct(private readonly PDO $pdo) {}
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql);$s->execute($params);return $s; }
    public function add(string $project,string $url,string $secretEnv): string
    {
        WebhookDispatcher::host($url);
        if (!preg_match('/^CAJEER_WEBHOOK_[A-Z0-9_]{1,100}$/D',$secretEnv) || strlen(env_raw($secretEnv))<32) { throw new \InvalidArgumentException('Set a CAJEER_WEBHOOK_* secret of at least 32 bytes.'); }
        $this->pdo->beginTransaction();
        try {
            $this->sql('SELECT id FROM projects WHERE id=:id FOR UPDATE',['id'=>$project]);
            if ((int)$this->sql('SELECT count(*) FROM integration_webhooks WHERE project_id=:project AND enabled',['project'=>$project])->fetchColumn()>=20) { throw new \RuntimeException('Maximum 20 active webhooks per project.'); }
            $id=$this->sql('INSERT INTO integration_webhooks(project_id,url,secret_env) VALUES (:project,:url,:secret) RETURNING id',['project'=>$project,'url'=>$url,'secret'=>$secretEnv])->fetchColumn();
            $this->pdo->commit(); return (string)$id;
        } catch (\Throwable $e) { $this->pdo->rollBack();throw $e; }
    }
    public function disable(string $id): bool
    {
        $this->pdo->beginTransaction();
        try {
            $changed=$this->sql('UPDATE integration_webhooks SET enabled=false WHERE id=:id AND enabled',['id'=>$id])->rowCount()===1;
            $this->sql("UPDATE webhook_deliveries SET status='cancelled',completed_at=now() WHERE webhook_id=:id AND status='pending'",['id'=>$id]);
            $this->pdo->commit();return $changed;
        } catch (\Throwable $e) { $this->pdo->rollBack();throw $e; }
    }
    public function list(): array { return $this->sql('SELECT id,project_id,url,secret_env,enabled,created_at FROM integration_webhooks ORDER BY created_at DESC LIMIT 1000')->fetchAll(); }
    public function history(string $id): array { return $this->sql('SELECT a.*,d.status FROM webhook_attempts a JOIN webhook_deliveries d ON d.id=a.delivery_id WHERE d.webhook_id=:id ORDER BY a.started_at DESC,a.attempt DESC LIMIT 100',['id'=>$id])->fetchAll(); }
    public function retry(string $id): bool
    {
        // Keep attempt history and stable delivery ID; allow another bounded series of attempts.
        return $this->sql("UPDATE webhook_deliveries d SET status='pending',next_attempt_at=now(),completed_at=NULL,retry_limit=attempts+8 FROM integration_webhooks h WHERE d.id=:id AND d.webhook_id=h.id AND h.enabled AND d.status='failed'",['id'=>$id])->rowCount()===1;
    }
    public function claim(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            // Lock delivery before attempt history, in the same order as finish().
            $row=$this->sql("SELECT d.id,d.payload,d.attempts,d.retry_limit,d.status,h.url,h.secret_env,h.enabled FROM webhook_deliveries d JOIN integration_webhooks h ON h.id=d.webhook_id WHERE (d.status='pending' AND d.next_attempt_at<=now()) OR (d.status='sending' AND d.lease_until<=now()) ORDER BY d.next_attempt_at,d.id LIMIT 1 FOR UPDATE OF d SKIP LOCKED")->fetch();
            if (!$row) { $this->pdo->commit();return null; }
            if ($row['status']==='sending') {
                $this->sql("UPDATE webhook_attempts SET outcome='unknown',finished_at=now() WHERE delivery_id=:id AND attempt=:attempt AND outcome='sending'",['id'=>$row['id'],'attempt'=>$row['attempts']]);
            }
            if (!$row['enabled'] || ($row['status']==='sending' && (int)$row['attempts']>=(int)$row['retry_limit'])) {
                $this->sql("UPDATE webhook_deliveries SET status=:status,completed_at=now(),lease_id=NULL,lease_until=NULL WHERE id=:id",['status'=>$row['enabled']?'failed':'cancelled','id'=>$row['id']]);
                $this->pdo->commit();return ['skipped'=>true];
            }
            $lease=$this->sql("UPDATE webhook_deliveries SET status='sending',attempts=attempts+1,lease_until=now()+interval '2 minutes',lease_id=gen_random_uuid() WHERE id=:id RETURNING lease_id,attempts",['id'=>$row['id']])->fetch();
            $this->sql('INSERT INTO webhook_attempts(delivery_id,attempt) VALUES (:id,:attempt)',['id'=>$row['id'],'attempt'=>$lease['attempts']]);
            $this->pdo->commit();return array_replace($row,$lease);
        } catch (\Throwable $e) { $this->pdo->rollBack();throw $e; }
    }
    public function finish(array $row,?int $status): string
    {
        $success=$status!==null && $status>=200 && $status<300;
        $retry=($status===null || in_array($status,[408,425,429,500,502,503,504],true)) && (int)$row['attempts']<(int)$row['retry_limit'];
        $result=$success?'delivered':($retry?'retry':'failed');
        $delay=min(3600,30*(2**(max(0, (int)$row['attempts']-((int)$row['retry_limit']-self::MAX_ATTEMPTS)-1))));
        $this->pdo->beginTransaction();
        try {
            $changed=$this->sql("UPDATE webhook_deliveries SET status=:status,next_attempt_at=now()+CAST(:delay AS integer)*interval '1 second',lease_id=NULL,lease_until=NULL,completed_at=CASE WHEN CAST(:terminal AS boolean) THEN now() ELSE NULL END WHERE id=:id AND lease_id=:lease AND status='sending'",['status'=>$retry?'pending':$result,'delay'=>$delay,'terminal'=>$retry?'false':'true','id'=>$row['id'],'lease'=>$row['lease_id']])->rowCount();
            if ($changed===1) { $this->sql('UPDATE webhook_attempts SET outcome=:outcome,http_status=:status,finished_at=now() WHERE delivery_id=:id AND attempt=:attempt',['outcome'=>$result,'status'=>$status,'id'=>$row['id'],'attempt'=>$row['attempts']]); }
            $this->pdo->commit();return $changed===1?$result:'unknown';
        } catch (\Throwable $e) { $this->pdo->rollBack();throw $e; }
    }
    public function cleanup(): int
    {
        return $this->pdo->exec("DELETE FROM webhook_deliveries WHERE id IN (SELECT id FROM webhook_deliveries WHERE status IN ('delivered','failed','cancelled') AND completed_at<now()-interval '30 days' ORDER BY completed_at LIMIT 10000 FOR UPDATE SKIP LOCKED)");
    }
    /** A bounded batch for cron/systemd. Transport injection is used only by integration tests. */
    public function work(int $limit=20,?callable $transport=null): array
    {
        if ($limit<1 || $limit>100) { throw new \InvalidArgumentException('Worker limit 1..100.'); }
        if ($transport===null && !extension_loaded('curl')) { throw new \RuntimeException('Missing ext-curl for webhook worker.'); }
        $transport??=(new WebhookDispatcher())->dispatch(...);
        $counts=['delivered'=>0,'retry'=>0,'failed'=>0,'unknown'=>0];
        for ($i=0;$i<$limit;$i++) {
            $row=$this->claim();if ($row===null) { break; }
            if (isset($row['skipped'])) { continue; }
            try { $status=$transport($row['url'],$row['id'],$row['payload'],env_raw($row['secret_env'])); }
            catch (\Throwable) { $status=null; }
            $counts[$this->finish($row,$status)]++;
        }
        return $counts;
    }
}

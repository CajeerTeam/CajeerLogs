<?php
declare(strict_types=1);
namespace Cajeer\Logs\Integration;

use Cajeer\Logs\Database\TransactionManager;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** Durable at-least-once notifications; all project-scoped administrative operations are SQL scoped. */
final class WebhookOutbox
{
    public const EVENTS=['logs.ingested','source.created','source.disabled','source.enabled','source.archived','source.restored'];
    private const MAX_ATTEMPTS=8;
    public function __construct(private readonly PDO $pdo) {}
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql);$s->execute($params);return $s; }
    private function transaction(callable $callback): mixed
    {
        // IdentityController already owns the authorization+audit transaction.
        return $this->pdo->inTransaction() ? $callback() : (new TransactionManager($this->pdo))->run($callback,maxAttempts:3);
    }
    public static function events(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value===[] || count($value)>count(self::EVENTS)) throw new HttpException(422,'invalid_events','Select one or more supported webhook events.');
        foreach ($value as $event) if (!is_string($event) || !in_array($event,self::EVENTS,true)) throw new HttpException(422,'invalid_events','Unsupported webhook event.');
        if (count(array_unique($value))!==count($value)) throw new HttpException(422,'invalid_events','Duplicate webhook event.');
        return $value;
    }
    private static function secret(string $name): string
    {
        if (!preg_match('/^CAJEER_WEBHOOK_[A-Z0-9_]{1,100}$/D',$name) || strlen(env_raw($name))<32) throw new HttpException(422,'invalid_secret','Set a CAJEER_WEBHOOK_* secret of at least 32 bytes in the worker and API environment.');
        return $name;
    }
    private static function url(string $url): string { try { WebhookDispatcher::host($url); } catch (\InvalidArgumentException) { throw new HttpException(422,'invalid_webhook_url','Only public HTTPS hostnames on port 443 are allowed.'); } return $url; }
    private static function enabledValue(mixed $value): bool
    {
        // pdo_pgsql may return native bool or PostgreSQL's 't'/'f' strings.
        return in_array($value,[true,1,'1','t','true'],true);
    }
    private static function decode(array $row): array
    {
        $raw=$row['events']??'{logs.ingested}';
        // PostgreSQL array is emitted by the driver as a string. Names are strictly enumerated.
        if (is_string($raw)) $row['events']=$raw==='{}'?[]:explode(',',trim($raw,'{}'));
        $row['enabled']=self::enabledValue($row['enabled']);
        $row['revision']=(int)$row['revision'];
        return $row;
    }
    public function add(string $project,string $url,string $secretEnv,array $events=['logs.ingested']): string
    {
        self::url($url);self::secret($secretEnv);$events=self::events($events);
        return $this->transaction(function () use ($project,$url,$secretEnv,$events) {
            if (!$this->sql('SELECT id FROM projects WHERE id=:id FOR UPDATE',['id'=>$project])->fetch()) throw new HttpException(404,'not_found','Project not found.');
            if ((int)$this->sql('SELECT count(*) FROM integration_webhooks WHERE project_id=:project',['project'=>$project])->fetchColumn()>=100) throw new HttpException(409,'conflict','Maximum 100 subscriptions per project.');
            if ((int)$this->sql('SELECT count(*) FROM integration_webhooks WHERE project_id=:project AND enabled',['project'=>$project])->fetchColumn()>=20) throw new HttpException(409,'conflict','Maximum 20 active webhooks per project.');
            $id=$this->sql('INSERT INTO integration_webhooks(project_id,url,secret_env,events) VALUES (:project,:url,:secret,CAST(:events AS text[])) RETURNING id',['project'=>$project,'url'=>$url,'secret'=>$secretEnv,'events'=>'{'.implode(',',$events).'}'])->fetchColumn();
            return (string)$id;
        });
    }
    public function list(): array { return $this->sql('SELECT id,project_id,url,secret_env,events,enabled,revision,created_at,updated_at FROM integration_webhooks ORDER BY created_at DESC LIMIT 1000')->fetchAll(); }
    public function listProject(string $project): array
    {
        return array_map(self::decode(...),$this->sql('SELECT id,project_id,url,secret_env,events,enabled,revision,created_at,updated_at FROM integration_webhooks WHERE project_id=:project ORDER BY created_at DESC,id LIMIT 100',['project'=>$project])->fetchAll());
    }
    public function detail(string $project,string $id): array
    {
        $row=$this->sql('SELECT id,project_id,url,secret_env,events,enabled,revision,created_at,updated_at FROM integration_webhooks WHERE project_id=:project AND id=:id',['project'=>$project,'id'=>$id])->fetch();
        if (!$row) throw new HttpException(404,'not_found','Webhook not found.');
        return self::decode($row);
    }
    public function update(string $project,string $id,int $revision,array $changes): array
    {
        if ($revision<1 || $changes===[] || array_diff(array_keys($changes),['url','secret_env','events','enabled'])!==[]) throw new HttpException(422,'invalid_payload','Invalid webhook update.');
        if (array_key_exists('url',$changes)) { if (!is_string($changes['url'])) throw new HttpException(422,'invalid_payload','Invalid URL.'); self::url($changes['url']); }
        if (array_key_exists('secret_env',$changes)) { if (!is_string($changes['secret_env'])) throw new HttpException(422,'invalid_payload','Invalid secret name.'); self::secret($changes['secret_env']); }
        if (array_key_exists('events',$changes)) $changes['events']=self::events($changes['events']);
        if (array_key_exists('enabled',$changes) && !is_bool($changes['enabled'])) throw new HttpException(422,'invalid_payload','enabled must be boolean.');
        return $this->transaction(function () use ($project,$id,$revision,$changes) {
            // Serialize re-enable operations against project-wide active count, even across servers.
            if (!$this->sql('SELECT id FROM projects WHERE id=:project FOR UPDATE',['project'=>$project])->fetch()) throw new HttpException(404,'not_found','Project not found.');
            $old=$this->detail($project,$id);
            if ($old['revision']!==$revision) throw new HttpException(409,'conflict','Webhook changed. Refresh before updating.');
            if (!$old['enabled'] && ($changes['enabled']??false)) self::secret($changes['secret_env']??$old['secret_env']);
            if (!$old['enabled'] && ($changes['enabled']??false) && (int)$this->sql('SELECT count(*) FROM integration_webhooks WHERE project_id=:project AND enabled',['project'=>$project])->fetchColumn()>=20) throw new HttpException(409,'conflict','Maximum 20 active webhooks per project.');
            $assign=[]; $params=['project'=>$project,'id'=>$id,'revision'=>$revision];
            foreach ($changes as $key=>$value) { $assign[]=$key.'='.($key==='events'?'CAST(:events AS text[])':':'.$key); $params[$key]=$key==='events'?'{'.implode(',',$value).'}':($key==='enabled'?($value?'true':'false'):$value); }
            $row=$this->sql('UPDATE integration_webhooks SET '.implode(',',$assign).',revision=revision+1,updated_at=now() WHERE project_id=:project AND id=:id AND revision=:revision RETURNING id,project_id,url,secret_env,events,enabled,revision,created_at,updated_at',$params)->fetch();
            if (!$row) throw new HttpException(409,'conflict','Webhook update conflicted.');
            if (isset($changes['enabled']) && !$changes['enabled']) $this->sql("UPDATE webhook_deliveries SET status='cancelled',completed_at=now() WHERE webhook_id=:id AND status='pending'",['id'=>$id]);
            return self::decode($row);
        });
    }
    public function disable(string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $changed=$this->sql('UPDATE integration_webhooks SET enabled=false,revision=revision+1,updated_at=now() WHERE id=:id AND enabled',['id'=>$id])->rowCount()===1;
            $this->sql("UPDATE webhook_deliveries SET status='cancelled',completed_at=now() WHERE webhook_id=:id AND status='pending'",['id'=>$id]);
            return $changed;
        });
    }
    public function test(string $project,string $id): string
    {
        return $this->transaction(function () use ($project,$id) {
            $row=$this->sql('SELECT id FROM integration_webhooks WHERE project_id=:project AND id=:id AND enabled FOR UPDATE',['project'=>$project,'id'=>$id])->fetch();
            if (!$row) throw new HttpException(404,'not_found','Enabled webhook not found.');
            if ((int)$this->sql("SELECT count(*) FROM webhook_deliveries WHERE webhook_id=:id AND status IN ('pending','sending') AND payload->>'event'='webhook.test'",['id'=>$id])->fetchColumn()>=10) throw new HttpException(409,'conflict','Too many outstanding test deliveries.');
            // Deliberately not a fabricated log event: receivers can distinguish health probes.
            $payload=json_encode(['event'=>'webhook.test','project_id'=>$project,'sent_at'=>gmdate('Y-m-d\TH:i:s\Z')],JSON_THROW_ON_ERROR);
            return (string)$this->sql('INSERT INTO webhook_deliveries(webhook_id,payload) VALUES (:id,CAST(:payload AS jsonb)) RETURNING id',['id'=>$id,'payload'=>$payload])->fetchColumn();
        });
    }
    public function deliveries(string $project,string $id,int $limit=50): array
    {
        if ($limit<1||$limit>100) throw new HttpException(422,'invalid_query','Limit must be 1..100.');
        $this->detail($project,$id);
        // Never return the webhook payload or signing material to an admin page.
        return $this->sql('SELECT d.id,d.status,d.attempts,d.retry_limit,d.created_at,d.completed_at,d.next_attempt_at,d.payload->>\'event\' AS event,(SELECT http_status FROM webhook_attempts a WHERE a.delivery_id=d.id ORDER BY attempt DESC LIMIT 1) AS last_http_status FROM webhook_deliveries d JOIN integration_webhooks h ON h.id=d.webhook_id WHERE h.project_id=:project AND d.webhook_id=:id ORDER BY d.created_at DESC,d.id DESC LIMIT '.(int)$limit,['project'=>$project,'id'=>$id])->fetchAll();
    }
    public function history(string $id): array { return $this->sql('SELECT a.*,d.status FROM webhook_attempts a JOIN webhook_deliveries d ON d.id=a.delivery_id WHERE d.webhook_id=:id ORDER BY a.started_at DESC,a.attempt DESC LIMIT 100',['id'=>$id])->fetchAll(); }
    public function historyProject(string $project,string $id): array
    {
        $this->detail($project,$id);
        return $this->sql('SELECT a.delivery_id,a.attempt,a.started_at,a.finished_at,a.outcome,a.http_status FROM webhook_attempts a JOIN webhook_deliveries d ON d.id=a.delivery_id JOIN integration_webhooks h ON h.id=d.webhook_id WHERE d.webhook_id=:id AND h.project_id=:project ORDER BY a.started_at DESC,a.attempt DESC LIMIT 100',['id'=>$id,'project'=>$project])->fetchAll();
    }
    public function stats(string $project): array
    {
        return $this->sql("SELECT h.id,COUNT(d.id) AS total,COUNT(d.id) FILTER (WHERE d.status='pending') AS pending,COUNT(d.id) FILTER (WHERE d.status='sending') AS sending,COUNT(d.id) FILTER (WHERE d.status='delivered') AS delivered,COUNT(d.id) FILTER (WHERE d.status='failed') AS failed,COUNT(d.id) FILTER (WHERE d.status='cancelled') AS cancelled FROM integration_webhooks h LEFT JOIN webhook_deliveries d ON d.webhook_id=h.id WHERE h.project_id=:project GROUP BY h.id",['project'=>$project])->fetchAll();
    }
    public function retry(string $id,?string $project=null,?string $webhook=null): bool
    {
        // Preserve the delivery ID and original body. A successful delivery is never replayed.
        $query="UPDATE webhook_deliveries d SET status='pending',next_attempt_at=now(),completed_at=NULL,retry_limit=attempts+8 FROM integration_webhooks h WHERE d.id=:id AND d.webhook_id=h.id AND h.enabled AND d.status='failed'";
        $params=['id'=>$id];
        if ($project!==null) { $query.=' AND h.project_id=:project'; $params['project']=$project; }
        if ($webhook!==null) { $query.=' AND h.id=:webhook'; $params['webhook']=$webhook; }
        return $this->sql($query,$params)->rowCount()===1;
    }
    public function claim(): ?array
    {
        return (new TransactionManager($this->pdo))->run(function () {
            $row=$this->sql("SELECT d.id,d.payload,d.attempts,d.retry_limit,d.status,h.url,h.secret_env,h.enabled FROM webhook_deliveries d JOIN integration_webhooks h ON h.id=d.webhook_id WHERE (d.status='pending' AND d.next_attempt_at<=now()) OR (d.status='sending' AND d.lease_until<=now()) ORDER BY d.next_attempt_at,d.id LIMIT 1 FOR UPDATE OF d SKIP LOCKED")->fetch();
            if (!$row) return null;
            $row['enabled']=self::enabledValue($row['enabled']);
            if ($row['status']==='sending') $this->sql("UPDATE webhook_attempts SET outcome='unknown',finished_at=now() WHERE delivery_id=:id AND attempt=:attempt AND outcome='sending'",['id'=>$row['id'],'attempt'=>$row['attempts']]);
            if (!$row['enabled'] || ($row['status']==='sending' && (int)$row['attempts']>=(int)$row['retry_limit'])) {
                $this->sql("UPDATE webhook_deliveries SET status=:status,completed_at=now(),lease_id=NULL,lease_until=NULL WHERE id=:id",['status'=>$row['enabled']?'failed':'cancelled','id'=>$row['id']]);
                return ['skipped'=>true];
            }
            $lease=$this->sql("UPDATE webhook_deliveries SET status='sending',attempts=attempts+1,lease_until=now()+interval '2 minutes',lease_id=gen_random_uuid() WHERE id=:id RETURNING lease_id,attempts",['id'=>$row['id']])->fetch();
            $this->sql('INSERT INTO webhook_attempts(delivery_id,attempt) VALUES (:id,:attempt)',['id'=>$row['id'],'attempt'=>$lease['attempts']]);
            return array_replace($row,$lease);
        }, maxAttempts: 3);
    }
    public function finish(array $row,?int $status): string
    {
        $success=$status!==null && $status>=200 && $status<300;
        $retry=($status===null || in_array($status,[408,425,429,500,502,503,504],true)) && (int)$row['attempts']<(int)$row['retry_limit'];
        $result=$success?'delivered':($retry?'retry':'failed');
        $delay=min(3600,30*(2**(max(0, (int)$row['attempts']-((int)$row['retry_limit']-self::MAX_ATTEMPTS)-1))));
        return (new TransactionManager($this->pdo))->run(function () use ($row,$status,$retry,$result,$delay) {
            $changed=$this->sql("UPDATE webhook_deliveries SET status=:status,next_attempt_at=now()+CAST(:delay AS integer)*interval '1 second',lease_id=NULL,lease_until=NULL,completed_at=CASE WHEN CAST(:terminal AS boolean) THEN now() ELSE NULL END WHERE id=:id AND lease_id=:lease AND status='sending'",['status'=>$retry?'pending':$result,'delay'=>$delay,'terminal'=>$retry?'false':'true','id'=>$row['id'],'lease'=>$row['lease_id']])->rowCount();
            if ($changed===1) $this->sql('UPDATE webhook_attempts SET outcome=:outcome,http_status=:status,finished_at=now() WHERE delivery_id=:id AND attempt=:attempt',['outcome'=>$result,'status'=>$status,'id'=>$row['id'],'attempt'=>$row['attempts']]);
            return $changed===1?$result:'unknown';
        }, maxAttempts: 3);
    }
    public function cleanup(int $days=30): int
    {
        if ($days<1 || $days>3650) throw new \InvalidArgumentException('Invalid webhook retention.');
        return $this->pdo->exec("DELETE FROM webhook_deliveries WHERE id IN (SELECT id FROM webhook_deliveries WHERE status IN ('delivered','failed','cancelled') AND completed_at<now()-interval '$days days' ORDER BY completed_at LIMIT 10000 FOR UPDATE SKIP LOCKED)");
    }
    /** Bound work for cron/systemd. Transport injection is for HTTP integration tests. */
    public function work(int $limit=20,?callable $transport=null): array
    {
        if ($limit<1 || $limit>100) throw new \InvalidArgumentException('Worker limit 1..100.');
        if ($transport===null && !extension_loaded('curl')) throw new \RuntimeException('Missing ext-curl for webhook worker.');
        $transport??=(new WebhookDispatcher())->dispatch(...);
        $counts=['delivered'=>0,'retry'=>0,'failed'=>0,'unknown'=>0];
        for ($i=0;$i<$limit;$i++) {
            $row=$this->claim();if ($row===null) break;
            if (isset($row['skipped'])) continue;
            try { $status=$transport($row['url'],$row['id'],$row['payload'],env_raw($row['secret_env'])); }
            catch (\Throwable) { $status=null; }
            $counts[$this->finish($row,$status)]++;
        }
        return $counts;
    }
}

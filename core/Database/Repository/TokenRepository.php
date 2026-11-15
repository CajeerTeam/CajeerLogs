<?php
declare(strict_types=1);
namespace Cajeer\Logs\Database\Repository;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\HttpException;
use PDO;

final class TokenRepository
{
    public const FIELDS='id,name,scopes,project_id,source_id,created_by,expires_at,revoked_at,last_used_at,created_at,description,requests_per_minute,revision,updated_at,service_account_id,rotated_to_id,rotated_at';
    public function __construct(private readonly PDO $pdo) {}
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    private function requireTransaction(): void { if (!$this->pdo->inTransaction()) throw new \LogicException('Token operation requires a transaction.'); }
    private static function invalid(string $field): never { throw new HttpException(422,'invalid_payload','Invalid token field: '.$field); }
    public static function scopes(mixed $scopes,?string $project): array
    {
        $allowed=$project===null?['system:read']:['logs:read','logs:write'];
        if (!is_array($scopes)||!array_is_list($scopes)||!$scopes||count($scopes)>count($allowed)) self::invalid('scopes');
        foreach($scopes as $s) if(!is_string($s)||!in_array($s,$allowed,true)) self::invalid('scopes');
        if(count(array_unique($scopes))!==count($scopes)) self::invalid('duplicate scopes');
        sort($scopes,SORT_STRING); return $scopes;
    }
    public static function narrowedScopes(array $old,?array $requested,?string $project): array
    {
        $old=self::scopes($old,$project); $new=$requested===null?$old:self::scopes($requested,$project);
        if(array_diff($new,$old)) throw new HttpException(422,'scope_escalation','Rotation cannot expand scopes.');
        return $new;
    }
    public static function ttl(mixed $v): int { if(!is_int($v)||$v<60||$v>31536000) self::invalid('expires_in'); return $v; }
    public static function grace(mixed $v): int { if(!is_int($v)||$v<0||$v>604800) self::invalid('overlap_seconds'); return $v; }
    public static function revision(mixed $v): string { if(is_int($v)) $v=(string)$v; if(!is_string($v)||!preg_match('/^[1-9][0-9]{0,18}$/D',$v)||(strlen($v)===19&&strcmp($v,'9223372036854775807')>0)) self::invalid('revision'); return $v; }
    public static function changes(array $data): array
    {
        if(!$data) self::invalid('empty patch');
        foreach($data as $key=>$v) {
            if($key==='name'||$key==='description') {
                if(!is_string($v)||strlen($v)>($key==='name'?255:2000)||str_contains($v,"\0")||preg_match('//u',$v)!==1||($key==='name'&&trim($v)==='')) self::invalid($key);
                if($key==='name') $data[$key]=trim($v);
            } elseif($key==='requests_per_minute') {
                if($v!==null&&(!is_int($v)||$v<1||$v>1000000)) self::invalid($key);
            } else self::invalid($key);
        }
        return $data;
    }
    public static function decode(array $row): array
    {
        if(is_string($row['scopes']??null)) $row['scopes']=json_decode($row['scopes'],false,32,JSON_THROW_ON_ERROR);
        if(isset($row['revision'])) $row['revision']=(string)$row['revision'];
        if(isset($row['requests_per_minute'])) $row['requests_per_minute']=(int)$row['requests_per_minute'];
        return $row;
    }
    /** Historical bootstrap callers may seed a pre-0011 schema. Only the digest is stored. */
    public function create(string $name,array $scopes,?string $projectId=null,?string $sourceId=null,?\DateTimeImmutable $expires=null): array
    {
        if (trim($name)===''||strlen($name)>255||str_contains($name,"\0")||($sourceId!==null&&$projectId===null)||($expires!==null&&$expires<=new \DateTimeImmutable())) throw new DatabaseException('Invalid token metadata.');
        $scopes=self::scopes($scopes,$projectId);
        $token='cl_'.bin2hex(random_bytes(32));
        $id=$this->sql('INSERT INTO api_tokens(name,token_hash,scopes,project_id,source_id,expires_at) VALUES (:name,:hash,CAST(:scopes AS jsonb),:project,:source,:expires) RETURNING id',['name'=>trim($name),'hash'=>hash('sha256',$token),'scopes'=>json_encode($scopes,JSON_THROW_ON_ERROR),'project'=>$projectId,'source'=>$sourceId,'expires'=>$expires?->format(DATE_ATOM)])->fetchColumn();
        return ['id'=>(string)$id,'token'=>$token];
    }
    public function issue(string $name,array $scopes,?string $project,?string $source,int $ttl,string $actor,?string $account=null,string $description='',?int $rate=null): array
    {
        $this->requireTransaction(); self::ttl($ttl); $meta=self::changes(['name'=>$name,'description'=>$description,'requests_per_minute'=>$rate]);
        if($account!==null) {
            $a=$this->sql('SELECT id FROM service_accounts WHERE project_id=:project AND id=:id AND disabled_at IS NULL FOR UPDATE',['project'=>$project,'id'=>$account])->fetchColumn();
            if(!$a) throw new HttpException(409,'service_account_inactive','Service account unavailable.');
        }
        // Expiry uses the database clock rather than the PHP host clock.
        $secret='cl_'.bin2hex(random_bytes(32)); $scopes=self::scopes($scopes,$project);
        $row=$this->sql("INSERT INTO api_tokens(name,token_hash,scopes,project_id,source_id,expires_at,created_by,description,requests_per_minute,service_account_id) VALUES (:name,:hash,CAST(:scopes AS jsonb),:project,:source,clock_timestamp()+CAST(:ttl AS integer)*interval '1 second',:actor,:description,:rate,:account) RETURNING ".self::FIELDS,['name'=>$meta['name'],'hash'=>hash('sha256',$secret),'scopes'=>json_encode($scopes,JSON_THROW_ON_ERROR),'project'=>$project,'source'=>$source,'ttl'=>$ttl,'actor'=>$actor,'description'=>$description,'rate'=>$rate,'account'=>$account])->fetch();
        return self::decode($row)+['token'=>$secret];
    }
    /** Lock on write-transaction authentication even when the usage timestamp is fresh. */
    public function findActive(string $token, bool $touch=true): ?array
    {
        if (!preg_match('/^cl_[a-f0-9]{64}$/D',$token)) return null;
        // Materialize the row lock before checking wall-clock expiry, even if a
        // concurrent administrator was holding a lock without changing the row.
        $transaction=$this->pdo->inTransaction();
        $from=$transaction?'locked':'api_tokens';
        $prefix=$transaction?'WITH locked AS MATERIALIZED (SELECT id,name,scopes,project_id,source_id,expires_at,requests_per_minute,service_account_id,last_used_at,revoked_at FROM api_tokens WHERE token_hash=:hash FOR UPDATE) ':'';
        $where=($transaction?'':'t.token_hash=:hash AND ').'t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at>clock_timestamp()) AND (t.service_account_id IS NULL OR EXISTS (SELECT 1 FROM service_accounts a WHERE a.id=t.service_account_id AND a.project_id=t.project_id AND a.disabled_at IS NULL))';
        $row=$this->sql($prefix."SELECT t.id,t.name,t.scopes,t.project_id,t.source_id,t.expires_at,t.requests_per_minute,t.service_account_id,(t.last_used_at IS NULL OR t.last_used_at<clock_timestamp()-interval '5 minutes') AS usage_due FROM $from t WHERE $where",['hash'=>hash('sha256',$token)])->fetch();
        if(!$row) return null;
        // Avoid a row update and WAL on every request. The conditional SQL handles concurrent first use.
        if($touch && $row['usage_due']) $this->sql("UPDATE api_tokens SET last_used_at=clock_timestamp() WHERE id=:id AND revoked_at IS NULL AND (last_used_at IS NULL OR last_used_at<clock_timestamp()-interval '5 minutes')",['id'=>$row['id']]);
        unset($row['usage_due']); return self::decode($row);
    }
    public function revoke(string $id): bool
    {
        return $this->sql('UPDATE api_tokens SET revoked_at=clock_timestamp(),updated_at=clock_timestamp(),revision=revision+1 WHERE id=:id AND revoked_at IS NULL',['id'=>$id])->rowCount()===1;
    }
    private function locked(?string $project,string $id,string $revision): array
    {
        $this->requireTransaction(); self::revision($revision);
        $row=$this->sql('WITH locked AS MATERIALIZED (SELECT '.self::FIELDS.' FROM api_tokens WHERE id=:id AND project_id IS NOT DISTINCT FROM CAST(:project AS uuid) FOR UPDATE) SELECT locked.*, (expires_at IS NULL OR expires_at>clock_timestamp()) AS unexpired FROM locked',['id'=>$id,'project'=>$project])->fetch();
        if(!$row) throw new HttpException(404,'not_found','Token not found.');
        if((string)$row['revision']!==$revision) throw new HttpException(409,'token_revision_conflict','Token changed; reload before retrying.');
        return self::decode($row);
    }
    public function update(?string $project,string $id,string $revision,array $changes): array
    {
        $row=$this->locked($project,$id,$revision); $changes=self::changes($changes);
        if($row['revoked_at']!==null) throw new HttpException(409,'token_inactive','Token revoked.');
        $sets=[]; foreach($changes as $k=>$v) $sets[]=$k.'=:'.$k;
        return self::decode($this->sql('UPDATE api_tokens SET '.implode(',',$sets).',revision=revision+1,updated_at=clock_timestamp() WHERE id=:id RETURNING '.self::FIELDS,$changes+['id'=>$id])->fetch());
    }
    public function rotate(?string $project,string $id,string $revision,int $ttl,int $overlap,string $actor,?array $scopes=null,string $signingMaster=''): array
    {
        $this->requireTransaction(); self::ttl($ttl); self::grace($overlap);
        if($overlap >= $ttl) self::invalid('overlap_seconds must be shorter than expires_in');
        // Account disable locks the account before its credentials. Keep the same order.
        $account=$this->sql('SELECT service_account_id FROM api_tokens WHERE id=:id AND project_id IS NOT DISTINCT FROM CAST(:project AS uuid)',['id'=>$id,'project'=>$project])->fetchColumn();
        if(is_string($account)) {
            if(!$this->sql('SELECT id FROM service_accounts WHERE id=:id AND project_id=:project AND disabled_at IS NULL FOR UPDATE',['id'=>$account,'project'=>$project])->fetchColumn()) throw new HttpException(409,'service_account_inactive','Service account unavailable.');
        }
        $row=$this->locked($project,$id,$revision);
        if($row['revoked_at']!==null||!$row['unexpired']||$row['rotated_to_id']!==null) throw new HttpException(409,'token_rotation_conflict','Token expired, revoked or already rotated.');
        $scopes=self::narrowedScopes($row['scopes'],$scopes,$project);
        $new=$this->issue($row['name'],$scopes,$project,$row['source_id'],$ttl,$actor,$row['service_account_id'],$row['description'],$row['requests_per_minute']);
        $this->sql('UPDATE api_tokens SET delivery_family_id=(SELECT COALESCE(delivery_family_id,id) FROM api_tokens WHERE id=:old) WHERE id=:new',['old'=>$id,'new'=>$new['id']]);
        $old=$this->sql("UPDATE api_tokens SET rotated_to_id=:successor,rotated_at=clock_timestamp(),updated_at=clock_timestamp(),revision=revision+1,expires_at=LEAST(expires_at,clock_timestamp()+CAST(:overlap AS integer)*interval '1 second'),revoked_at=CASE WHEN CAST(:immediate AS integer)=0 THEN clock_timestamp() ELSE revoked_at END WHERE id=:id RETURNING ".self::FIELDS,['successor'=>$new['id'],'overlap'=>$overlap,'immediate'=>$overlap,'id'=>$id])->fetch();
        if ($project!==null && in_array('logs:write',$scopes,true)) {
            $signing=(new \Cajeer\Logs\Security\IngestSigning($this->pdo,$signingMaster))->inherit($project,$id,$new['id']);
            if ($signing!==[]) $new['signing']=$signing;
        }
        return $new+['previous'=>self::decode($old)];
    }
    /** Bulk revoke in a deterministic row order. Caller verifies the filter's ownership. */
    public function revokeAll(?string $project,?string $source=null,?string $account=null): int
    {
        $this->requireTransaction();
        $where='project_id IS NOT DISTINCT FROM CAST(:project AS uuid) AND revoked_at IS NULL'; $params=['project'=>$project];
        if($source!==null) {$where.=' AND source_id=:source';$params['source']=$source;}
        if($account!==null) {$where.=' AND service_account_id=:account';$params['account']=$account;}
        return $this->sql('WITH selected AS (SELECT id FROM api_tokens WHERE '.$where.' ORDER BY id FOR UPDATE) UPDATE api_tokens t SET revoked_at=clock_timestamp(),updated_at=clock_timestamp(),revision=revision+1 FROM selected WHERE t.id=selected.id',$params)->rowCount();
    }
}

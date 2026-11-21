<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Database\{DatabaseException,PostgresBoolean};
use Cajeer\Logs\Http\{HttpException,Request};
use PDO;

/** Separate signing secrets are encrypted, bound to token and key UUID, and never listed. */
final class IngestSigning
{
    public function __construct(private readonly PDO $pdo, private readonly string $master) {}
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    private function encryptionKey(): string
    {
        if (!TwoFactorManager::keyValid($this->master)) throw new DatabaseException('Signing encryption key unavailable.');
        return hash_hkdf('sha256',hex2bin($this->master),32,'cajeerlogs:ingest-signing:v2');
    }
    public function seal(string $token,string $id,string $secret): string
    {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($token.':'.$id.':'.$secret,$nonce,$this->encryptionKey()));
    }
    public function open(string $token,string $id,string $cipher): string
    {
        $raw=base64_decode($cipher,true); $n=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw===false || strlen($raw)<$n+SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new DatabaseException('Invalid encrypted signing key.');
        $plain=sodium_crypto_secretbox_open(substr($raw,$n),substr($raw,0,$n),$this->encryptionKey());
        $prefix=$token.':'.$id.':';
        if ($plain===false || !str_starts_with($plain,$prefix) || !preg_match('/^sk_[a-f0-9]{64}$/D',substr($plain,strlen($prefix)))) throw new DatabaseException('Cannot decrypt signing key.');
        return substr($plain,strlen($prefix));
    }
    public function verify(Request $r,IngestPrincipal $p,string $bearer,?string $key,bool $global,bool $locked=false): ?string
    {
        if ($locked && !$this->pdo->inTransaction()) throw new \LogicException('Signing policy lock requires transaction.');
        // Token row is already locked by IngestAuthorization. FOR SHARE keeps
        // the HMAC policy stable against concurrent UPDATE/DELETE, without
        // conflicting with the FOR KEY SHARE parent locks taken by PostgreSQL
        // when async queue workers persist log_events. FOR UPDATE here made
        // ingest token->project lock order deadlock with worker project->token
        // foreign-key checks under sustained production load.
        $project=$this->sql('SELECT require_ingest_hmac FROM projects WHERE id=:id'.($locked?' FOR SHARE':''),['id'=>$p->projectId])->fetch();
        if (!$project) Identity::forbidden();
        $row=$this->sql('SELECT current_key_id,current_ciphertext,previous_key_id,previous_ciphertext,(previous_until>clock_timestamp()) AS previous_active FROM ingest_signing WHERE token_id=:id',['id'=>$p->id])->fetch();
        $now=(int)$this->pdo->query('SELECT floor(extract(epoch FROM clock_timestamp()))::bigint')->fetchColumn();
        $signer=new SignedPayload(); $id=$r->header('X-Log-Key-Id');
        if ($row && $row['current_key_id']!==null) {
            if ($id!==null && $id===$row['current_key_id']) $cipher=$row['current_ciphertext'];
            elseif ($id!==null && $id===$row['previous_key_id'] && PostgresBoolean::required($row['previous_active'])) $cipher=$row['previous_ciphertext'];
            else SignedPayload::invalid();
            return $signer->verify($r,$this->open($p->id,$id,$cipher),$key,true,$now,$id);
        }
        return $signer->verify($r,$bearer,$key,$global||PostgresBoolean::required($project['require_ingest_hmac'])||$row!==false,$now);
    }
    private function lockToken(string $project,string $token): void
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Signing change requires transaction.');
        $row=$this->sql('WITH locked AS MATERIALIZED (SELECT id,scopes,revoked_at,expires_at,service_account_id,project_id FROM api_tokens WHERE id=:id AND project_id=:project FOR UPDATE) SELECT id,scopes,revoked_at,(expires_at IS NULL OR expires_at>clock_timestamp()) AND (service_account_id IS NULL OR EXISTS (SELECT 1 FROM service_accounts a WHERE a.id=locked.service_account_id AND a.project_id=locked.project_id AND a.disabled_at IS NULL)) AS active FROM locked',['id'=>$token,'project'=>$project])->fetch();
        if (!$row) throw new HttpException(404,'not_found','Token not found.');
        if ($row['revoked_at']!==null || !PostgresBoolean::required($row['active']) || !in_array('logs:write',\Cajeer\Logs\Database\Repository\TokenRepository::scopes(json_decode($row['scopes'],false,32,JSON_THROW_ON_ERROR),$project),true)) throw new HttpException(409,'token_inactive','Token cannot sign ingest.');
    }
    public function metadata(string $project,string $token): array
    {
        $row=$this->sql('SELECT s.current_key_id,s.previous_key_id,s.previous_until,s.updated_at,(s.token_id IS NOT NULL) AS required FROM api_tokens t LEFT JOIN ingest_signing s ON s.token_id=t.id WHERE t.id=:id AND t.project_id=:project',['id'=>$token,'project'=>$project])->fetch();
        if (!$row) throw new HttpException(404,'not_found','Token not found.');
        $row['required']=PostgresBoolean::required($row['required']);
        return $row;
    }
    /** First provision switches immediately to separate mode. Subsequent rotations retain at most one previous key. */
    public function configure(string $project,string $token,string $mode,int $overlap,?string $expectedKeyId=null): array
    {
        if (!in_array($mode,['bearer','separate'],true) || $overlap<0 || $overlap>604800 || ($mode==='bearer' && $overlap!==0)) throw new HttpException(422,'invalid_payload','Invalid signing mode or overlap.');
        $this->lockToken($project,$token);
        $old=$this->metadata($project,$token);
        if ($old['current_key_id']!==$expectedKeyId) throw new HttpException(409,'signing_key_conflict','Signing key changed; reload metadata before rotating.');
        if ($mode==='bearer') {
            if ($old['current_key_id']!==null) throw new HttpException(409,'signing_downgrade','Separate signing cannot be downgraded.');
            $this->sql('INSERT INTO ingest_signing(token_id) VALUES (:id) ON CONFLICT DO NOTHING',['id'=>$token]);
            return $this->metadata($project,$token);
        }
        if ($old['current_key_id']===null && $overlap!==0) throw new HttpException(422,'invalid_payload','First separate key requires zero overlap.');
        $id=(string)$this->pdo->query('SELECT gen_random_uuid()')->fetchColumn(); $secret='sk_'.bin2hex(random_bytes(32));
        $cipher=$this->seal($token,$id,$secret);
        $this->sql("INSERT INTO ingest_signing(token_id,current_key_id,current_ciphertext) VALUES (:token,:id,:cipher)
            ON CONFLICT(token_id) DO UPDATE SET previous_key_id=CASE WHEN CAST(:overlap1 AS integer)>0 THEN ingest_signing.current_key_id END,
            previous_ciphertext=CASE WHEN CAST(:overlap2 AS integer)>0 THEN ingest_signing.current_ciphertext END,
            previous_until=CASE WHEN CAST(:overlap3 AS integer)>0 THEN clock_timestamp()+CAST(:overlap4 AS integer)*interval '1 second' END,
            current_key_id=EXCLUDED.current_key_id,current_ciphertext=EXCLUDED.current_ciphertext,updated_at=clock_timestamp()",
            ['token'=>$token,'id'=>$id,'cipher'=>$cipher,'overlap1'=>$overlap,'overlap2'=>$overlap,'overlap3'=>$overlap,'overlap4'=>$overlap]);
        return $this->metadata($project,$token)+['signing_secret'=>$secret];
    }
    /** End the overlap without replacing the current key. Lock in the same order as ingest. */
    public function retirePrevious(string $project,string $token,string $current,string $previous): array
    {
        $this->lockToken($project,$token);
        $meta=$this->metadata($project,$token);
        if ($meta['current_key_id']!==$current || $meta['previous_key_id']!==$previous) {
            throw new HttpException(409,'signing_key_conflict','Signing key changed; reload metadata before retiring.');
        }
        $this->sql('UPDATE ingest_signing SET previous_key_id=NULL,previous_ciphertext=NULL,previous_until=NULL,updated_at=clock_timestamp() WHERE token_id=:id',['id'=>$token]);
        return $this->metadata($project,$token);
    }
    /** Token rotation must not silently downgrade the successor to optional bearer signing. */
    public function inherit(string $project,string $old,string $new): array
    {
        $meta=$this->metadata($project,$old);
        if (!PostgresBoolean::required($meta['required'])) return [];
        return $this->configure($project,$new,$meta['current_key_id']===null?'bearer':'separate',0);
    }
}

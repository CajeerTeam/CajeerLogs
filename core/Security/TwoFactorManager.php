<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** RFC 6238 TOTP, encrypted at rest, single-use time steps and recovery codes. */
final class TwoFactorManager
{
    public function __construct(private readonly PDO $pdo, private readonly string $key, private readonly bool $required = false, private readonly string $activeKey = '', private readonly string $previousKey = '') {}
    public static function fromConfig(PDO $pdo, \Cajeer\Logs\Config\ConfigRepository $c): self { return new self($pdo,$c->get('security.totp_key'),$c->get('security.require_admin_totp'),$c->get('security.totp_active_key',''),$c->get('security.totp_previous_key','')); }
    public function requiredForAdmins(): bool { return $this->required; }
    public static function base32(string $bytes): string
    {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $buffer=0; $bits=0; $out='';
        foreach (str_split($bytes) as $byte) { $buffer=($buffer<<8)|ord($byte); $bits+=8; while ($bits>=5) { $bits-=5; $out.=$alphabet[($buffer>>$bits)&31]; } $buffer&=(1<<$bits)-1; }
        if ($bits) { $out.=$alphabet[($buffer<<(5-$bits))&31]; } return $out;
    }
    public static function code(string $secret,int $step,int $digits=6): string
    {
        if ($step<0 || !in_array($digits,[6,8],true)) { throw new \InvalidArgumentException('Invalid TOTP parameters.'); }
        $mac=hash_hmac('sha1',pack('N2',intdiv($step,4294967296),$step%4294967296),$secret,true);
        $offset=ord($mac[19])&15; $n=unpack('N',substr($mac,$offset,4))[1]&0x7fffffff;
        return str_pad((string)($n%(10**$digits)),$digits,'0',STR_PAD_LEFT);
    }
    public static function matchingStep(string $secret,string $code,int $now,int $last): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D',$code)) { return null; }
        $step=intdiv($now,30); $matched=null;
        for ($i=$step-1;$i<=$step+1;$i++) { if ($i>=0 && $i>$last && hash_equals(self::code($secret,$i),$code)) { $matched=$i; } }
        return $matched;
    }
    public static function keyValid(string $key): bool { return extension_loaded('sodium') && preg_match('/^[a-f0-9]{64}$/iD',$key)===1 && preg_match('/^([a-f0-9])\1{63}$/iD',$key)!==1; }
    private static function encryptionKey(string $key,string $domain): string
    {
        if (!self::keyValid($key)) throw new DatabaseException('Configured TOTP encryption key is unavailable.');
        return hash_hkdf('sha256',hex2bin($key),32,$domain);
    }
    public static function keyId(string $key): string { return hash('sha256',self::encryptionKey($key,'cajeerlogs:totp:key-id:v2')); }
    public function activePrefix(): string { return $this->activeKey===''?'':'v2:'.self::keyId($this->activeKey).':'; }
    public function seal(string $id,string $secret): string
    {
        $prefix=$this->activePrefix();
        $key=self::encryptionKey($prefix===''?$this->key:$this->activeKey,$prefix===''?'cajeerlogs:totp:v1':'cajeerlogs:totp:v2');
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $prefix.base64_encode($nonce.sodium_crypto_secretbox($id.':'.$secret,$nonce,$key));
    }
    public function open(string $id,string $cipher): string
    {
        if (str_starts_with($cipher,'v2:')) {
            if (!preg_match('/^v2:([a-f0-9]{64}):([A-Za-z0-9+\/=]+)$/D',$cipher,$parts)) throw new DatabaseException('Invalid encrypted TOTP secret.');
            $candidate=null;
            foreach ([$this->activeKey,$this->previousKey] as $key) if (self::keyValid($key) && hash_equals(self::keyId($key),$parts[1])) { $candidate=$key; break; }
            if ($candidate===null) throw new DatabaseException('TOTP key version is unavailable.');
            $key=self::encryptionKey($candidate,'cajeerlogs:totp:v2'); $cipher=$parts[2];
        } else $key=self::encryptionKey($this->key,'cajeerlogs:totp:v1');
        $raw=base64_decode($cipher,true); $size=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw===false || strlen($raw)<$size+SODIUM_CRYPTO_SECRETBOX_MACBYTES) throw new DatabaseException('Invalid encrypted TOTP secret.');
        $plain=sodium_crypto_secretbox_open(substr($raw,$size),substr($raw,0,$size),$key);
        if ($plain===false || !str_starts_with($plain,$id.':') || strlen($plain)!==strlen($id)+21) throw new DatabaseException('Cannot decrypt TOTP secret.');
        return substr($plain,strlen($id)+1);
    }
    /** Bounded, restartable rotation. The same identity lock coordinates login/enrollment. */
    public function rotateBatch(int $limit=100): array {
        $prefix=$this->activePrefix();
        if ($prefix==='' || $limit<1 || $limit>500) throw new DatabaseException('Configure a TOTP active key and a batch size of 1..500.');
        return (new \Cajeer\Logs\Database\TransactionManager($this->pdo))->run(function () use ($limit,$prefix) {
            (new Identity($this->pdo,true,''))->lock();
            $rows=$this->sql("SELECT user_id,secret_ciphertext FROM user_totp WHERE left(secret_ciphertext,:length)<>:prefix ORDER BY user_id LIMIT $limit FOR UPDATE",['length'=>strlen($prefix),'prefix'=>$prefix])->fetchAll();
            foreach ($rows as $row) {
                $secret=$this->open($row['user_id'],$row['secret_ciphertext']);
                $cipher=$this->seal($row['user_id'],$secret);
                if (!hash_equals($secret,$this->open($row['user_id'],$cipher))) throw new DatabaseException('TOTP rotation verification failed.');
                $this->sql('UPDATE user_totp SET secret_ciphertext=:cipher WHERE user_id=:id',['cipher'=>$cipher,'id'=>$row['user_id']]);
                sodium_memzero($secret);
            }
            $remaining=(int)$this->sql('SELECT count(*) FROM user_totp WHERE left(secret_ciphertext,:length)<>:prefix',['length'=>strlen($prefix),'prefix'=>$prefix])->fetchColumn();
            if ($rows!==[]) (new \Cajeer\Logs\Audit\AuditLogger($this->pdo))->record('auth.totp_key_rotated',['rotated'=>count($rows),'remaining'=>$remaining]);
            return ['rotated'=>count($rows),'remaining'=>$remaining,'active_key_id'=>self::keyId($this->activeKey)];
        });
    }
    /** Verify all rows in bounded pages, including pending enrollment. No secret is returned. */
    public function verifyKeys(): array {
        $after=null; $count=0; $legacy=0; $prefix=$this->activePrefix();
        do {
            $rows=$after===null?$this->sql('SELECT user_id,secret_ciphertext FROM user_totp ORDER BY user_id LIMIT 100')->fetchAll():$this->sql('SELECT user_id,secret_ciphertext FROM user_totp WHERE user_id>:after ORDER BY user_id LIMIT 100',['after'=>$after])->fetchAll();
            foreach ($rows as $row) { $secret=$this->open($row['user_id'],$row['secret_ciphertext']); sodium_memzero($secret); $after=$row['user_id']; $count++; if ($prefix!==''&&!str_starts_with($row['secret_ciphertext'],$prefix)) $legacy++; }
        } while(count($rows)===100);
        return ['verified'=>$count,'remaining'=>$legacy];
    }
    private function sql(string $sql,array $params=[]): \PDOStatement { $s=$this->pdo->prepare($sql); $s->execute($params); return $s; }
    private function transaction(): void { if (!$this->pdo->inTransaction()) { throw new \LogicException('TOTP changes require identity transaction.'); } }
    public function setup(string $id,string $email,string $session): array
    {
        $this->transaction(); $secret=random_bytes(20);
        $saved=$this->sql("INSERT INTO user_totp(user_id,secret_ciphertext,pending_session,pending_until) VALUES (:id,:secret,:session,now()+interval '10 minutes') ON CONFLICT(user_id) DO UPDATE SET secret_ciphertext=EXCLUDED.secret_ciphertext,pending_session=EXCLUDED.pending_session,pending_until=EXCLUDED.pending_until,last_step=-1 WHERE user_totp.pending_session IS NOT NULL",['id'=>$id,'secret'=>$this->seal($id,$secret),'session'=>$session]);
        if ($saved->rowCount()!==1) { throw new DatabaseException('Inconsistent TOTP enrollment state.'); }
        $base32=self::base32($secret);
        return ['secret'=>$base32,'otpauth_uri'=>'otpauth://totp/'.rawurlencode('CajeerLogs:'.$email).'?secret='.$base32.'&issuer=CajeerLogs&algorithm=SHA1&digits=6&period=30','expires_in'=>600];
    }
    public function enable(string $id,string $session,string $code): array
    {
        $this->transaction();
        $row=$this->sql('SELECT secret_ciphertext FROM user_totp WHERE user_id=:id AND pending_session=:session AND pending_until>now() FOR UPDATE',['id'=>$id,'session'=>$session])->fetch();
        $step=$row?self::matchingStep($this->open($id,$row['secret_ciphertext']),$code,time(),-1):null;
        if ($step===null) { throw new HttpException(422,'invalid_otp','Invalid or expired TOTP enrollment.'); }
        $this->sql('UPDATE user_totp SET pending_session=NULL,pending_until=NULL,last_step=:step WHERE user_id=:id',['step'=>$step,'id'=>$id]);
        $this->sql('UPDATE users SET two_factor_enabled=true,updated_at=now() WHERE id=:id',['id'=>$id]);
        return $this->regenerateRecoveryCodes($id);
    }
    /** Caller must verify password and a fresh second factor (or finish enrollment). */
    public function regenerateRecoveryCodes(string $id): array
    {
        $this->transaction();
        $this->sql('DELETE FROM user_recovery_codes WHERE user_id=:id',['id'=>$id]); $codes=[];
        for($i=0;$i<10;$i++) { $code=bin2hex(random_bytes(16)); $codes[]=$code; $this->sql('INSERT INTO user_recovery_codes(user_id,code_hash) VALUES (:id,:hash)',['id'=>$id,'hash'=>hash('sha256',$code)]); }
        return $codes;
    }
    public function verify(string $id,?string $code): bool
    {
        $this->transaction(); if ($code===null) { return false; }
        $row=$this->sql('SELECT secret_ciphertext,last_step FROM user_totp WHERE user_id=:id AND pending_session IS NULL FOR UPDATE',['id'=>$id])->fetch();
        if (!$row) { return false; }
        if (preg_match('/^[a-f0-9]{32}$/D',$code)) { return $this->sql('DELETE FROM user_recovery_codes WHERE user_id=:id AND code_hash=:hash RETURNING code_hash',['id'=>$id,'hash'=>hash('sha256',$code)])->fetchColumn()!==false; }
        $step=self::matchingStep($this->open($id,$row['secret_ciphertext']),$code,time(),(int)$row['last_step']);
        if ($step===null) { return false; }
        $this->sql('UPDATE user_totp SET last_step=:step WHERE user_id=:id',['id'=>$id,'step'=>$step]); return true;
    }
    public function disable(string $id): void
    {
        $this->transaction(); $this->sql('DELETE FROM user_recovery_codes WHERE user_id=:id',['id'=>$id]);
        $this->sql('DELETE FROM user_totp WHERE user_id=:id',['id'=>$id]);
        $this->sql('UPDATE users SET two_factor_enabled=false,updated_at=now() WHERE id=:id',['id'=>$id]);
    }
}

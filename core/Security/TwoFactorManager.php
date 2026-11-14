<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Http\HttpException;
use PDO;

/** RFC 6238 TOTP, encrypted at rest, single-use time steps and recovery codes. */
final class TwoFactorManager
{
    public function __construct(private readonly PDO $pdo, private readonly string $key, private readonly bool $required = false) {}
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
    private function encryptionKey(): string
    {
        if (!self::keyValid($this->key)) { throw new DatabaseException('Configure SECURITY_TOTP_KEY as a random 32-byte hex key and enable sodium.'); }
        return hash_hkdf('sha256',hex2bin($this->key),32,'cajeerlogs:totp:v1');
    }
    public function seal(string $id,string $secret): string
    {
        $key=$this->encryptionKey(); $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($id.':'.$secret,$nonce,$key));
    }
    public function open(string $id,string $cipher): string
    {
        $key=$this->encryptionKey(); $raw=base64_decode($cipher,true); $size=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw===false || strlen($raw)<$size+SODIUM_CRYPTO_SECRETBOX_MACBYTES) { throw new DatabaseException('Invalid encrypted TOTP secret.'); }
        $plain=sodium_crypto_secretbox_open(substr($raw,$size),substr($raw,0,$size),$key);
        if ($plain===false || !str_starts_with($plain,$id.':') || strlen($plain)!==strlen($id)+21) { throw new DatabaseException('Cannot decrypt TOTP secret.'); }
        return substr($plain,strlen($id)+1);
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

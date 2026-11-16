<?php
declare(strict_types=1);
namespace Cajeer\Logs\Operations;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\SecureFile;

/** Authenticated streaming archive: bounded frames, authenticated manifest and explicit final tag. */
final class BackupVault
{
    private const MAGIC="CAJEERBACKUP\x01";
    private const CHUNK=1048576;
    public function __construct(private readonly string $key) {
        if (strlen($key)!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) throw new DatabaseException('Backup encryption key must be 32 bytes.');
    }
    public static function key(string $hex): string {
        if (!preg_match('/^[a-fA-F0-9]{64}$/D',$hex)) throw new DatabaseException('BACKUP_ENCRYPTION_KEY must be 64 hexadecimal characters.');
        return hex2bin($hex);
    }
    private static function write($out,string $data): void {
        for ($offset=0,$length=strlen($data);$offset<$length;) {
            $n=fwrite($out,substr($data,$offset));
            if ($n===false || $n===0) throw new DatabaseException('Backup write failed.');
            $offset+=$n;
        }
    }
    private static function read($in,int $length): string {
        $data='';
        while (strlen($data)<$length) {
            $part=fread($in,$length-strlen($data));
            if ($part===false || $part==='') throw new DatabaseException('Encrypted backup truncated.');
            $data.=$part;
        }
        return $data;
    }
    private static function frame($out,string &$state,string $data,int $tag): void {
        $cipher=sodium_crypto_secretstream_xchacha20poly1305_push($state,$data,'',$tag);
        self::write($out,pack('N',strlen($cipher)).$cipher);
    }
    private static function pull($in,string &$state,int $max): array {
        $n=unpack('N',self::read($in,4))[1];
        $overhead=SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        if ($n<$overhead || $n>$max+$overhead) throw new DatabaseException('Invalid encrypted backup frame.');
        $frame=sodium_crypto_secretstream_xchacha20poly1305_pull($state,self::read($in,$n));
        if ($frame===false) throw new DatabaseException('Encrypted backup authentication failed.');
        return $frame;
    }
    private static function manifest(array $m): void {
        if (($m['format']??null)!==1 || !is_string($m['id']??null) || !preg_match('/^backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}$/D',$m['id']) || !is_int($m['bytes']??null) || $m['bytes']<1 || !is_string($m['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$m['sha256']) || !is_string($m['version']??null) || !is_string($m['schema']??null) || !preg_match('/^[a-z_][a-z0-9_]{0,62}$/D',$m['schema'])) throw new DatabaseException('Invalid encrypted backup manifest.');
    }
    public function encrypt(string $archive,array $m,string $destination): void {
        self::manifest($m); SecureFile::assertPrivate($archive);
        $in=fopen($archive,'rb'); if ($in===false) throw new DatabaseException('Cannot open backup.');
        $out=null; $created=false; $old=umask(0077);
        try {
            SecureFile::verifyHandle($in,$archive);
            $out=@fopen($destination,'xb'); if ($out===false) throw new DatabaseException('Cannot create encrypted backup.'); $created=true;
            SecureFile::verifyHandle($out,$destination);
            [$state,$header]=sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            self::write($out,self::MAGIC.$header);
            $json=json_encode($m,JSON_THROW_ON_ERROR); if (strlen($json)>16384) throw new DatabaseException('Backup manifest too large.');
            self::frame($out,$state,$json,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $hash=hash_init('sha256'); $bytes=0;
            while (!feof($in)) {
                $data=fread($in,self::CHUNK); if ($data===false) throw new DatabaseException('Backup read failed.');
                if ($data==='') break;
                $bytes+=strlen($data); hash_update($hash,$data);
                self::frame($out,$state,$data,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            }
            if ($bytes!==$m['bytes'] || !hash_equals($m['sha256'],hash_final($hash))) throw new DatabaseException('Backup changed during encryption.');
            self::frame($out,$state,'',SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (!fflush($out)||!fsync($out)) throw new DatabaseException('Cannot synchronize encrypted backup.');
        } catch (\Throwable $e) { if ($created) @unlink($destination); throw $e; }
        finally { umask($old); fclose($in); if (is_resource($out)) fclose($out); }
    }
    /** Verify every byte, optionally extracting into a newly created private file. */
    public function decrypt(string $package,?string $destination=null,?string $expectedId=null): array {
        SecureFile::assertPrivate($package);
        $in=fopen($package,'rb'); if ($in===false) throw new DatabaseException('Cannot open encrypted backup.');
        $out=null; $created=false; $old=umask(0077);
        try {
            SecureFile::verifyHandle($in,$package);
            if (self::read($in,strlen(self::MAGIC))!==self::MAGIC) throw new DatabaseException('Unknown encrypted backup format.');
            $state=sodium_crypto_secretstream_xchacha20poly1305_init_pull(self::read($in,SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),$this->key);
            [$json,$tag]=self::pull($in,$state,16384);
            $m=json_decode($json,true,8,JSON_THROW_ON_ERROR);
            if (!is_array($m)||$tag!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) throw new DatabaseException('Invalid encrypted backup manifest.');
            self::manifest($m);
            if ($expectedId!==null && $m['id']!==$expectedId) throw new DatabaseException('Encrypted backup ID mismatch.');
            if ($destination!==null) {
                $out=@fopen($destination,'xb'); if ($out===false) throw new DatabaseException('Cannot create extracted backup.'); $created=true;
                SecureFile::verifyHandle($out,$destination);
            }
            $hash=hash_init('sha256'); $bytes=0;
            while (true) {
                [$data,$tag]=self::pull($in,$state,self::CHUNK);
                if ($tag===SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if ($data!=='' || fread($in,1)!=='' || !feof($in)) throw new DatabaseException('Invalid encrypted backup ending.');
                    break;
                }
                if ($tag!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE || $data==='') throw new DatabaseException('Invalid encrypted backup data frame.');
                $bytes+=strlen($data); if ($bytes>$m['bytes']) throw new DatabaseException('Encrypted backup size mismatch.');
                hash_update($hash,$data); if (is_resource($out)) self::write($out,$data);
            }
            if ($bytes!==$m['bytes'] || !hash_equals($m['sha256'],hash_final($hash))) throw new DatabaseException('Encrypted backup checksum mismatch.');
            if (is_resource($out) && (!fflush($out)||!fsync($out))) throw new DatabaseException('Cannot synchronize extracted backup.');
            return $m;
        } catch (\Throwable $e) { if ($created) @unlink($destination); throw $e; }
        finally { umask($old); fclose($in); if (is_resource($out)) fclose($out); }
    }
}

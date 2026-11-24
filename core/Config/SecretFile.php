<?php
declare(strict_types=1);
namespace Cajeer\Logs\Config;

use Cajeer\Logs\Database\DatabaseException;
use Cajeer\Logs\Installer\SecureFile;

/** Explicit allowlist; no arbitrary file reads via ordinary environment settings. */
final class SecretFile
{
    public const KEYS=['DB_PASSWORD','SECURITY_HMAC_SECRET','SECURITY_TOKEN_PEPPER','SECURITY_PRIVACY_HASH_PEPPER','SECURITY_TOTP_KEY','SECURITY_TOTP_ACTIVE_KEY','SECURITY_TOTP_PREVIOUS_KEY','WEBHOOK_SIGNING_SECRET','PROMETHEUS_TOKEN','BACKUP_ENCRYPTION_KEY','SKIF4ER_ID_CLIENT_SECRET','OPENAI_API_KEY'];

    public static function raw(string $key, array $dotenv, string $default=''): string
    {
        $process=getenv($key);
        $direct=$process!==false ? $process : ($_ENV[$key]??$dotenv[$key]??$default);
        $path=self::reference($key,$dotenv);
        if ($path===null) return (string)$direct;
        // A placeholder from the example is not a credential. Real values must be explicitly removed.
        if ($direct!=='' && !str_starts_with((string)$direct,'change_me')) throw new DatabaseException('Supply either '.$key.' or '.$key.'_FILE, not both.');
        return self::read($path);
    }

    public static function reference(string $key,array $dotenv): ?string
    {
        if (!in_array($key,self::KEYS,true)) return null;
        $name=$key.'_FILE'; $process=getenv($name);
        $path=$process!==false ? $process : ($_ENV[$name]??$dotenv[$name]??'');
        if ($path==='') return null;
        if (!is_string($path)) throw new DatabaseException('Invalid secret file configuration.');
        return $path;
    }

    public static function read(string $path): string
    {
        if (!str_starts_with($path,'/') || str_contains($path,"\0") || realpath($path)!==$path || !SecureFile::privateFile($path) || !is_readable($path)) throw new DatabaseException('Secret file must be an absolute, private regular file, not a symlink or hard link.');
        $stream=@fopen($path,'rb');
        if ($stream===false) throw new DatabaseException('Cannot open secret file.');
        try {
            $s=SecureFile::verifyHandle($stream,$path);
            if ($s['size']>4096) throw new DatabaseException('Secret file exceeds 4096 bytes.');
            $v=stream_get_contents($stream,4097);
            if ($v===false || strlen($v)>4096 || str_contains($v,"\0")) throw new DatabaseException('Invalid secret file content.');
            $v=str_ends_with($v,"\r\n")?substr($v,0,-2):(str_ends_with($v,"\n")?substr($v,0,-1):$v);
            if ($v==='') throw new DatabaseException('Secret file is empty.');
            return $v;
        } finally { fclose($stream); }
    }
}

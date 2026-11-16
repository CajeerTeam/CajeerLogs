<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\DatabaseException;

/** Installer files must be in a private, operator-controlled directory. */
final class SecureFile
{
    public static function directory(string $path): bool
    {
        $real=realpath($path);
        $stat=$real!==false ? @stat($real) : false;
        return $real!==false && $real===rtrim($path,'/') && $stat!==false && is_dir($real)
            && is_writable($real) && ($stat['mode'] & 0022)===0
            && (!function_exists('posix_geteuid') || $stat['uid']===posix_geteuid());
    }

    public static function privateFile(string $path, bool $missingAllowed=false): bool
    {
        clearstatcache(true,$path);
        $s=@lstat($path);
        if ($s===false) return $missingAllowed && !is_link($path) && !file_exists($path);
        return self::privateStat($s);
    }

    private static function privateStat(array $s): bool
    {
        return ($s['mode'] & 0170000)===0100000 && in_array($s['mode'] & 07777,[0400,0600],true) && $s['nlink']===1
            && (!function_exists('posix_geteuid') || $s['uid']===posix_geteuid());
    }

    public static function assertDirectory(string $path): void
    {
        if (!self::directory($path)) throw new DatabaseException('Installer directory must be canonical, owned by the operator and not group/world writable.');
    }

    public static function assertPrivate(string $path, bool $missingAllowed=false): void
    {
        if (!self::privateFile($path,$missingAllowed)) throw new DatabaseException('Installer file must be private, owned by the operator, regular and not linked (0600/0400).');
    }

    public static function verifyHandle($stream,string $path): array
    {
        clearstatcache(true,$path);
        $s=fstat($stream); $named=@lstat($path);
        if ($s===false || $named===false || !self::privateStat($s) || !self::privateStat($named) || $s['ino']!==$named['ino'] || $s['dev']!==$named['dev']) throw new DatabaseException('Installer file identity or private permissions changed.');
        return $s;
    }
}

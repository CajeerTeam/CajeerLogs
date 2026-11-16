<?php
declare(strict_types=1);
namespace Cajeer\Logs\Installer;

use Cajeer\Logs\Database\DatabaseException;

final class AdministratorPassword
{
    public static function validate(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 1024 || str_contains($password, "\0") || !defined('PASSWORD_ARGON2ID')) {
            throw new DatabaseException('Administrator needs a 12–1024 byte password and PHP Argon2id support.');
        }
    }

    /** Read a local secret (never from a command argument or persisted dotenv). */
    public static function supplied(string $env, string $fileEnv): string
    {
        $value = getenv($env);
        $path = getenv($fileEnv);
        if ($value !== false && $path !== false) { throw new DatabaseException('Supply either password environment variable or password file, not both.'); }
        if ($path === false) { return $value === false ? '' : $value; }
        if ($path === '' || is_link($path) || !is_file($path) || !is_readable($path)) { throw new DatabaseException('Password file must be a readable regular file, not a symlink.'); }
        $f = fopen($path, 'rb');
        if ($f === false) { throw new DatabaseException('Cannot open password file.'); }
        try {
            $stat = fstat($f);
            $named = lstat($path);
            if ($stat === false || $named === false || $stat['ino'] !== $named['ino'] || $stat['dev'] !== $named['dev'] || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0077) !== 0 || $stat['nlink'] !== 1 || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) || $stat['size'] > 1026) {
                throw new DatabaseException('Password file must be private (0600/0400), regular and at most 1026 bytes.');
            }
            $password = stream_get_contents($f, 1027);
            if ($password === false || strlen($password) > 1026) { throw new DatabaseException('Cannot read password file.'); }
            // Remove one terminal newline, not whitespace belonging to the password.
            return str_ends_with($password, "\r\n") ? substr($password, 0, -2) : (str_ends_with($password, "\n") ? substr($password, 0, -1) : $password);
        } finally { fclose($f); }
    }
}

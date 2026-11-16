<?php
declare(strict_types=1);

namespace Cajeer\Logs\Config;

final class Environment
{
    /** @return array<string,string> */
    public static function load(string $file, bool $runtime = false): array
    {
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            throw new \RuntimeException('Environment must be a regular file.');
        }
        if (!file_exists($file)) {
            return [];
        }
        clearstatcache(true,$file);
        $before=@lstat($file);
        if ($before===false || ($before['mode'] & 0170000)!==0100000 || $before['nlink']!==1 || $before['size']>1048576) {
            throw new \RuntimeException('Environment must be an unlinked regular file of at most 1 MiB.');
        }
        $stream=@fopen($file,'rb');
        if ($stream===false) throw new \RuntimeException('Cannot read environment file.');
        try {
            $opened=fstat($stream);
            if ($opened===false || $opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino'] || $opened['mode']!==$before['mode'] || $opened['nlink']!==1 || $opened['uid']!==$before['uid']) throw new \RuntimeException('Environment file changed while opening.');
            $contents=stream_get_contents($stream,1048577);
            clearstatcache(true,$file); $after=@lstat($file);
            if ($contents===false || strlen($contents)>1048576 || $after===false || $after['dev']!==$opened['dev'] || $after['ino']!==$opened['ino'] || $after['mode']!==$opened['mode'] || $after['uid']!==$opened['uid'] || $after['nlink']!==1 || $after['size']!==strlen($contents)) throw new \RuntimeException('Environment file changed while reading.');
            $lines=preg_split('/\r\n|\n|\r/',$contents);
        } finally { fclose($stream); }
        $values = [];
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/D', $line, $match)) {
                throw new \RuntimeException('Invalid .env assignment at line ' . ($i + 1));
            }
            [, $key, $raw] = $match;
            if (isset($values[$key])) {
                throw new \RuntimeException('Duplicate .env key at line ' . ($i + 1));
            }
            if (str_starts_with($raw, '"')) {
                if (!preg_match('/^"((?:[^"\\\\]|\\\\.)*)"\s*(?:#.*)?$/D', $raw, $quoted)) {
                    throw new \RuntimeException('Invalid quoted .env value at line ' . ($i + 1));
                }
                $value = strtr($quoted[1], ['\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
            } elseif (str_starts_with($raw, "'")) {
                if (!preg_match("/^'([^']*)'\\s*(?:#.*)?$/D", $raw, $quoted)) {
                    throw new \RuntimeException('Invalid quoted .env value at line ' . ($i + 1));
                }
                $value = $quoted[1];
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
            }
            $values[$key] = $value;
        }
        $environment=getenv('APP_ENV');
        if ($runtime && ($environment===false ? ($_ENV['APP_ENV']??$values['APP_ENV']??'production') : $environment)==='production') {
            if (!in_array($opened['mode'] & 07777,[0400,0600],true) || (function_exists('posix_geteuid') && $opened['uid']!==posix_geteuid())) throw new \RuntimeException('Production environment file must be owned by the runtime and private (0400/0600).');
        }
        return $values;
    }
}

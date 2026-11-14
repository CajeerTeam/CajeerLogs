<?php
declare(strict_types=1);
namespace Cajeer\Logs\Storage;

/** Resolve relative paths inside a trusted application directory, never follow symlinks. */
final class LocalDisk implements StorageDiskInterface
{
    public function __construct(private readonly string $root = __DIR__.'/../../storage/app') {}
    public function path(string $path): string
    {
        if ($path===''||str_contains($path,'\\')||preg_match('/[\x00-\x1f\x7f]/',$path)) { throw new \InvalidArgumentException('Invalid storage path.'); }
        $parts=explode('/',$path); $root=realpath($this->root);
        if ($root===false || !is_dir($root)||is_link($this->root)) { throw new \RuntimeException('Storage root unavailable.'); }
        $current=$root;
        foreach ($parts as $i=>$part) {
            if ($part===''||$part==='.'||$part==='..'||str_contains($part,':')) { throw new \InvalidArgumentException('Invalid storage path.'); }
            $current.='/'.$part;
            if (is_link($current) || (file_exists($current)&&$i<count($parts)-1&&!is_dir($current))) { throw new \InvalidArgumentException('Unsafe storage path.'); }
        }
        return $current;
    }
}

<?php
declare(strict_types=1);

namespace Cajeer\Logs\Kernel;

final class Version
{
    public static function read(string $basePath): string
    {
        $raw = file_get_contents($basePath . '/release.json');
        if ($raw === false) {
            throw new \RuntimeException('Cannot read release metadata.');
        }
        $release = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        $version = $release['version'] ?? null;
        if (!is_string($version) || !preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D', $version)) {
            throw new \RuntimeException('Invalid release version.');
        }
        return $version;
    }
}

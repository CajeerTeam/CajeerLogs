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
        // Deployment orchestrators declare the version expected of each running
        // process. Refuse mixed binary/DB-worker/API fleets even if an old image
        // was accidentally retagged with the current Compose version.
        $expected = getenv('CAJEER_RELEASE_VERSION');
        if ($expected !== false && $expected !== '') {
            if (!preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $expected)
                || !hash_equals($version, $expected)) {
                throw new \RuntimeException('Shipped application version does not match CAJEER_RELEASE_VERSION.');
            }
        }
        return $version;
    }
}

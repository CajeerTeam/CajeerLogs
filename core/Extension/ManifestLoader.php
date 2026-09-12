<?php
declare(strict_types=1);

namespace Cajeer\Logs\Extension;

final class ManifestLoader
{
    public function load(string $path): ExtensionManifest
{
    $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    return new ExtensionManifest($data['name'], $data['type'], $data['version']);
}
}

<?php
declare(strict_types=1);

namespace Cajeer\Logs\Update;

final class GitFlicRegistryClient
{
    public function endpoint(): string
{
    return getenv('UPDATE_REGISTRY_URL') ?: '';
}
}

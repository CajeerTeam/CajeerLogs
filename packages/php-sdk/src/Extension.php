<?php
declare(strict_types=1);

namespace Cajeer\Logs\Sdk;

interface Extension
{
    public function boot(ExtensionContext $context): void;
}

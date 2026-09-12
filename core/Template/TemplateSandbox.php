<?php
declare(strict_types=1);

namespace Cajeer\Logs\Template;

final class TemplateSandbox
{
    public function allows(string $function): bool
{
    return !in_array($function, ['exec', 'shell_exec', 'system'], true);
}
}

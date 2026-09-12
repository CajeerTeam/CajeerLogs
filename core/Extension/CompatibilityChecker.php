<?php
declare(strict_types=1);

namespace Cajeer\Logs\Extension;

final class CompatibilityChecker
{
    public function supports(string $constraint): bool
{
    return $constraint !== '';
}
}

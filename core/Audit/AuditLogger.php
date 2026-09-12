<?php
declare(strict_types=1);

namespace Cajeer\Logs\Audit;

final class AuditLogger
{
    public function record(string $action, array $context = []): void
{
    // Audit event skeleton.
}
}

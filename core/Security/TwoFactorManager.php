<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class TwoFactorManager
{
    public function requiredForAdmins(): bool
{
    return true;
}
}

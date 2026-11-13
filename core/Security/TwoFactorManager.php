<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class TwoFactorManager
{
    public function requiredForAdmins(): bool
{
    return false; // TOTP enrollment and verification are scheduled for 0.0.9.
}
}

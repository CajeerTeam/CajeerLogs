<?php
declare(strict_types=1);

namespace Cajeer\Logs\Security;

final class ApiToken
{
    public function __construct(public readonly string $id, public readonly array $scopes) {}
}

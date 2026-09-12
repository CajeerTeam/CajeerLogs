<?php
declare(strict_types=1);

namespace Cajeer\Logs\Update;

final class UpdateResolver
{
    public function channel(): string
{
    return getenv('UPDATE_CHANNEL') ?: 'stable';
}
}

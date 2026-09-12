<?php
declare(strict_types=1);

namespace Cajeer\Logs\Theme;

final class ThemeConfig
{
    public function __construct(public readonly string $name, public readonly array $config = []) {}
}

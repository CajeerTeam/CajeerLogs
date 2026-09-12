<?php
declare(strict_types=1);

namespace Cajeer\Logs\Config;

final class ConfigRepository
{
    /** @param array<string,mixed> $items */
public function __construct(private array $items = []) {}

public function get(string $key, mixed $default = null): mixed
{
    $segments = explode('.', $key);
    $value = $this->items;
    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

public function set(string $key, mixed $value): void
{
    $this->items[$key] = $value;
}
}

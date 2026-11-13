<?php
declare(strict_types=1);

namespace Cajeer\Logs\Config;

final class ConfigRepository
{
    /** @param array<string,mixed> $items */
    public function __construct(private array $items = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        if (in_array('', $segments, true)) {
            throw new \InvalidArgumentException('Empty configuration key segment.');
        }
        $target = &$this->items;
        foreach ($segments as $segment) {
            if (!is_array($target)) {
                $target = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass();
        return $this->get($key, $missing) !== $missing;
    }
}

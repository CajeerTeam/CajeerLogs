<?php
declare(strict_types=1);

namespace Cajeer\Logs\Localization;

final class Translator
{
    public function trans(string $key, array $replace = [], string $locale = 'ru'): string
{
    foreach ($replace as $name => $value) { $key = str_replace(':' . $name, (string) $value, $key); }
    return $key;
}
}

<?php
declare(strict_types=1);

namespace Cajeer\Logs\Search;

interface SearchEngineInterface
{
    public function search(string $query): array;
}

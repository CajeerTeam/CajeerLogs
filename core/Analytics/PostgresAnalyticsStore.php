<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

final class PostgresAnalyticsStore
{
    public function record(array $event): void
{
    // PostgreSQL analytics baseline.
}

public function aggregate(array $filters = []): array
{
    return ['driver' => 'postgres', 'filters' => $filters, 'data' => []];
}
}

<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

final class ClickHouseAnalyticsStore
{
    public function record(array $event): void
{
    // ClickHouse is recommended for high-load analytics.
}

public function aggregate(array $filters = []): array
{
    return ['driver' => 'clickhouse', 'filters' => $filters, 'data' => []];
}
}

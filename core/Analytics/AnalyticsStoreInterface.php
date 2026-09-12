<?php
declare(strict_types=1);

namespace Cajeer\Logs\Analytics;

interface AnalyticsStoreInterface
{
    public function record(array $event): void;
public function aggregate(array $filters = []): array;
}

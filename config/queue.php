<?php
declare(strict_types=1);
return [
    'default' => 'pgsql',
    'worker' => [
        'processes' => env_raw('QUEUE_WORKER_PROCESSES', '2'),
        'max_jobs' => env_raw('QUEUE_WORKER_MAX_JOBS', '1000'),
        'max_seconds' => env_raw('QUEUE_WORKER_MAX_SECONDS', '3600'),
        'memory_mb' => env_raw('QUEUE_WORKER_MEMORY_MB', '256'),
        'poll_min_ms' => env_raw('QUEUE_POLL_MIN_MS', '50'),
        'poll_max_ms' => env_raw('QUEUE_POLL_MAX_MS', '1000'),
        'lease_seconds' => env_raw('QUEUE_LEASE_SECONDS', '120'),
        'claim_timeout_ms' => env_raw('QUEUE_CLAIM_TIMEOUT_MS', '5000'),
        'process_timeout_seconds' => env_raw('QUEUE_PROCESS_TIMEOUT_SECONDS', '90'),
        'shutdown_grace_seconds' => env_raw('QUEUE_SHUTDOWN_GRACE_SECONDS', '120'),
    ],
    'retry' => [
        'max_attempts' => env_raw('QUEUE_MAX_ATTEMPTS', '8'),
        'base_seconds' => env_raw('QUEUE_RETRY_BASE_SECONDS', '5'),
        'max_seconds' => env_raw('QUEUE_RETRY_MAX_SECONDS', '3600'),
        'jitter_percent' => env_raw('QUEUE_RETRY_JITTER_PERCENT', '25'),
        'outage_base_ms' => env_raw('QUEUE_OUTAGE_BASE_MS', '250'),
        'outage_max_ms' => env_raw('QUEUE_OUTAGE_MAX_MS', '30000'),
        'outage_max_failures' => env_raw('QUEUE_OUTAGE_MAX_FAILURES', '10'),
    ],
    'max_jobs' => env_raw('QUEUE_MAX_JOBS', '1000'),
];

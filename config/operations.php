<?php
declare(strict_types=1);
$int=static function(string $name,int $default,int $min,int $max): int {
    $value=filter_var(env_raw($name,(string)$default),FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if ($value===false) throw new RuntimeException('Invalid '.$name);
    return $value;
};
$window=env_raw('RETENTION_WINDOW_UTC');
\Cajeer\Logs\Lifecycle\Retention::windowOpen($window,0);
return [
    'retention_window_utc'=>$window,
    'retention_scheduler_enabled'=>match(env_raw('RETENTION_SCHEDULER_ENABLED','true')) {'true','1'=>true,'false','0'=>false,default=>throw new RuntimeException('Invalid RETENTION_SCHEDULER_ENABLED')},
    'storage_warn_bytes'=>$int('STORAGE_WARN_BYTES',1073741824,0,PHP_INT_MAX),
    'retention_batch_size'=>$int('RETENTION_BATCH_SIZE',1000,1,10000),
    'retention_timeout_ms'=>$int('RETENTION_TIMEOUT_MS',5000,100,60000),
    'retention_cycle_batches'=>$int('RETENTION_CYCLE_BATCHES',10,1,100),
    'retention_cycle_budget_ms'=>$int('RETENTION_CYCLE_BUDGET_MS',30000,100,300000),
    'retention_pause_ms'=>$int('RETENTION_PAUSE_MS',100,0,5000),
    'webhook_retention_days'=>$int('WEBHOOK_RETENTION_DAYS',30,1,3650),
    'queue_retention_floor_seconds'=>$int('QUEUE_RETENTION_FLOOR_SECONDS',0,0,31536000),
    'backup_timeout_seconds'=>$int('BACKUP_TIMEOUT_SECONDS',3600,1,86400),
    'backup_min_free_bytes'=>$int('BACKUP_MIN_FREE_BYTES',104857600,0,PHP_INT_MAX),
    'backup_interval_seconds'=>$int('BACKUP_INTERVAL_SECONDS',86400,60,31536000),
    'backup_keep_daily'=>$int('BACKUP_KEEP_DAILY',0,0,3650),
    'backup_keep_weekly'=>$int('BACKUP_KEEP_WEEKLY',0,0,520),
    'backup_keep_monthly'=>$int('BACKUP_KEEP_MONTHLY',0,0,120),
    'backup_offsite_distinct_device'=>match(env_raw('BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE','true')) {'true','1'=>true,'false','0'=>false,default=>throw new RuntimeException('Invalid BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE')},
    'backup_keep_local'=>$int('BACKUP_KEEP_LOCAL',0,0,10000),
    'backup_offsite_dir'=>env_raw('BACKUP_OFFSITE_DIR'),
    'backup_encryption_key'=>env_raw('BACKUP_ENCRYPTION_KEY'),
    'backup_dir'=>env_raw('BACKUP_DIR'),
    'pg_dump'=>env_raw('PG_DUMP_BIN','/usr/bin/pg_dump'),
    'pg_restore'=>env_raw('PG_RESTORE_BIN','/usr/bin/pg_restore'),
    'metrics_token'=>env_raw('PROMETHEUS_TOKEN'),
];

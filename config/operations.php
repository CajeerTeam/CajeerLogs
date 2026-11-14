<?php
declare(strict_types=1);
return [
    'backup_dir'=>env_raw('BACKUP_DIR'),
    'pg_dump'=>env_raw('PG_DUMP_BIN','/usr/bin/pg_dump'),
    'pg_restore'=>env_raw('PG_RESTORE_BIN','/usr/bin/pg_restore'),
    'metrics_token'=>env_raw('PROMETHEUS_TOKEN'),
];

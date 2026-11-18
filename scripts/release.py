#!/usr/bin/env python3
"""Deterministic CajeerLogs distribution builder and integrity verifier (stdlib only)."""
import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = 'RELEASE-MANIFEST.json'
FILES = {'.env.example', '.dockerignore', 'README.md', 'CHANGELOG.md', 'LICENSE', 'NOTICE',
         'SECURITY.md', 'THIRD_PARTY_NOTICES.md', 'TRADEMARKS.md', 'CONTRIBUTING.md',
         'CODE_OF_CONDUCT.md', 'release.json', 'composer.json', 'composer.lock',
         'phpstan.neon', 'phpunit.xml.dist', 'openapi.yaml', 'compose.yaml', 'compose.production.yaml'}
DIRS = {'admin', 'api', 'app', 'bin', 'clients', 'components', 'config', 'core', 'deploy',
        'docs', 'examples', 'installer', 'languages', 'migrations', 'modules', 'packages',
        'public', 'scripts', 'templates', 'tests'}
BLOCKED = {'vendor', 'node_modules', '__pycache__', 'build', 'dist', '.gradle', '.git', '.idea', '.vscode', 'release-out'}
SUFFIXES = {'.php', '.py', '.js', '.mjs', '.ts', '.json', '.yaml', '.yml', '.sql', '.md', '.txt',
            '.html', '.css', '.vue', '.svg', '.png', '.jpg', '.jpeg', '.ico', '.webp', '.sh',
            '.conf', '.service', '.timer', '.cron', '.toml', '.kts', '.kt', '.java', '.properties', '.xml', '.neon', '.example', '.cjr', '.webmanifest'}
REQUIRED = {'release.json', 'core/bootstrap.php', 'bin/cajeer', 'public/index.php', 'public/router.php',
            'admin/index.html', 'public/admin/assets/panel.js', 'public/admin/assets/panel.css',
            'api/openapi.yaml', 'openapi.yaml', 'compose.production.yaml', 'deploy/production/Dockerfile',
            'deploy/dev/init-db.sh', '.env.example', 'storage/.gitkeep', 'docs/First-Public-Release.md',
            'deploy/production/nginx.conf', 'deploy/production/gateway-origin.sh', 'core/Security/DeploymentSecurity.php', 'core/Security/TlsProbe.php', 'tests/deployment_security.php', 'tests/deployment_tls.py', 'tests/deployment_gateway.py', 'docs/Production-Security.md', 'deploy/production/pool.conf', 'deploy/production/maintenance.sh',
            'deploy/production/environment.example', 'scripts/release.py', 'tests/release.py',
            'tests/release_runtime.php', 'tests/production_smoke.py', 'tests/upgrade_release.py',
            'core/Security/IngestPrincipal.php', 'core/Security/IngestAuthorization.php', 'tests/ingest_authorization.php', 'docs/Ingest-Authorization.md',
            'core/Log/PageCursor.php', 'tests/pagination.php', 'docs/Pagination-Log-Explorer.md',
            'core/Log/StoredLogJson.php', 'tests/release_pipeline.php', 'tests/pipeline_scenario.py', 'tests/pipeline_database.py', 'docs/Log-Ingestion-Search-Release.md',
            'core/Log/SearchRuntime.php', 'tests/search_runtime.php', 'docs/Log-Search.md',
            'core/Log/DeliveryRepository.php', 'migrations/pgsql/0014_delivery_idempotency.sql', 'tests/delivery.php', 'tests/delivery_database.php', 'docs/Idempotency-Delivery-Guarantees.md',
            'core/Log/RedactionPolicy.php', 'core/Log/RedactionPolicies.php', 'tests/redaction.php', 'tests/redaction_database.php', 'docs/Redaction-Data-Privacy.md',
            'core/Log/StrictEventJson.php', 'tests/event_validation.php', 'docs/Event-Validation.md',
            'core/Log/IngestBatch.php', 'tests/batch_ingestion.php', 'docs/Single-Batch-Ingestion.md',
            'core/Log/IngestService.php', 'core/Log/IngestEnvelope.php', 'core/Log/IngestAdmission.php', 'core/Log/IngestBudget.php', 'core/Observability/IngestMetrics.php', 'tests/ingest_runtime.php', 'docs/Ingest-Runtime.md',
            'core/Database/ConnectionManager.php', 'core/Database/DatabaseFailure.php',
            'core/Observability/DatabaseRuntimeMetrics.php', 'docs/PostgreSQL-Runtime.md',
            'tests/postgres_runtime.php', 'tests/postgres_runtime_database.php', 'tests/postgres_split_role.php', 'tests/postgres_ci.sh',
            'core/Database/MigrationSql.php', 'core/Database/SchemaInspector.php', 'core/Database/MigrationGuard.php',
            'docs/Migration-Engine.md', 'tests/migration_engine.php', 'tests/migration_engine_database.php',
            'core/Installer/AdministratorPassword.php', 'core/Installer/InstallPreflight.php',
            'core/Installer/InstallationStatus.php', 'core/Installer/SchemaPreparation.php',
            'docs/Installer-Administrator.md', 'docs/Secure-Installer.md', 'tests/installer.php',
            'core/Config/SecretFile.php', 'core/Installer/SecureFile.php', 'core/Installer/UpgradePreflight.php', 'tests/secure_installer.php',
            'core/Database/IntegrityInspector.php', 'core/Database/PerformanceInspector.php',
            'core/Console/Command/DatabaseMaintenanceCommand.php', 'migrations/pgsql/0012_database_integrity.sql', 'migrations/pgsql/0013_ingest_signing.sql', 'core/Security/IngestSigning.php', 'tests/hmac.php', 'tests/hmac_database.php',
            'docs/Database-Integrity-Performance.md', 'scripts/benchmark.py', 'tests/benchmark.py',
            'tests/integrity.php', 'tests/integrity_database.php',
            'core/Database/RestoreFence.php', 'scripts/certify.py', 'tests/certify.py',
            'tests/restore_fence.php', 'docs/Database-Core-Release.md'}


REQUIRED.update({'clients/python/cajeer_batch_spool.py','clients/python/cajeer_logging.py',
                 'clients/python/cajeer_spool_cli.py','tests/python_sdk_stable.py',
                 'docs/Python-SDK-Stable.md'})

REQUIRED.update({'packages/php-sdk/src/DurableBatchDelivery.php','tests/sdk/php_buffered.php',
                 'tests/sdk/php_buffered_monolog.php','tests/sdk_buffered_wire.py',
                 'docs/PHP-Monolog-SDK-Stable.md'})

REQUIRED.update({
    'clients/java-kotlin/src/main/kotlin/ru/cajeer/logs/CajeerLogsAsyncQueue.kt',
    'clients/java-kotlin/src/main/kotlin/ru/cajeer/logs/CajeerLogsJulHandler.kt',
    'tests/kotlin_sdk_stable.py', 'tests/sdk/KotlinQueueTest.kt',
    'tests/sdk/KotlinQueueJavaTest.java', 'docs/Kotlin-SDK-Stable.md',
})

REQUIRED.update({'clients/python/cajeer_logs_collector.py', 'tests/nginx_import_stable.py', 'docs/Nginx-Log-Import-Stable.md'})
REQUIRED.update({'core/Integration/WebhookOutbox.php','migrations/pgsql/0026_webhook_subscriptions.sql',
                 'public/admin/assets/pages/webhooks.js','tests/webhook_subscriptions_database.php','docs/Webhooks-Stable.md'})

REQUIRED.update({'clients/python/cajeer_aapanel_manager.py', 'tests/aapanel_integration_stable.py',
                 'modules/aapanel-importer/README.md', 'examples/systemd/cajeerlogs-aapanel.service'})

REQUIRED.update({'core/Log/ExportQueue.php','core/Log/ExportBudgetExceeded.php',
                 'core/Http/FileDownloadResponse.php','core/Console/Command/ExportCommand.php',
                 'public/admin/assets/pages/exports.js','migrations/pgsql/0027_export_jobs.sql',
                 'tests/export_jobs.php','tests/export_jobs_database.php','docs/JSON-CSV-Export-Stable.md',
                 'examples/systemd/cajeerlogs-export-worker.service','examples/systemd/cajeerlogs-export-worker.timer',
                 'examples/systemd/cajeerlogs-export-cleanup.service','examples/systemd/cajeerlogs-export-cleanup.timer'})

REQUIRED.update({'docs/Integration-Compatibility-Certification.md',
                 'scripts/integrations.py', 'docs/SDK-Integrations-Stable-0.7.0.md'})

REQUIRED.update({'core/Analytics/ClickHouseHttpClient.php','core/Analytics/ClickHouseMigrator.php',
                 'core/Analytics/ClickHouseAnalyticsStore.php','core/Console/Command/ClickHouseCommand.php',
                 'migrations/clickhouse/0001_logs.sql','migrations/clickhouse/0002_legacy_scope.sql',
                 'tests/clickhouse_http.py','tests/clickhouse_database.php','docs/ClickHouse.md'})

REQUIRED.update({'core/Analytics/AnalyticsReplicator.php','core/Console/Command/AnalyticsReplicationCommand.php',
                 'migrations/pgsql/0028_analytics_replication.sql','migrations/clickhouse/0003_replicated_logs.sql',
                 'tests/analytics_replication_database.php','tests/analytics_replication.php',
                 'docs/Reliable-Analytics-Replication.md','examples/systemd/cajeerlogs-analytics-replication.service',
                 'examples/systemd/cajeerlogs-analytics-replication.timer'})

REQUIRED.update({'core/Analytics/PostgresRollupStore.php','core/Console/Command/AnalyticsRollupCommand.php',
                 'migrations/pgsql/0029_analytics_rollups.sql','tests/analytics_rollups.php',
                 'tests/analytics_rollups_database.php','docs/Aggregation-Engine.md'})

REQUIRED.update({'packages/ts-sdk/src/durable.ts', 'packages/ts-sdk/test/durable.test.mjs',
                 'packages/ts-sdk/test/process.mjs', 'docs/TypeScript-SDK-Stable.md'})

# Runtime module graph is required even for an unstaged changed-files overlay.
REQUIRED.update({
    'scripts/load_test.py','tests/load_test.py','docs/Load-Testing-Performance-Certification.md',
    'clients/python/cajeer_delivery_spool.py','tests/sdk_async_wire.py','tests/sdk/async.php','tests/sdk/async.mjs','tests/sdk/AsyncWireTest.kt',
    'packages/php-sdk/src/JobPendingException.php','packages/php-sdk/src/JobFailedException.php','packages/php-sdk/src/JobStatusException.php','docs/SDK-Delivery-Recovery.md',
    'core/Queue/QueueOperations.php','migrations/pgsql/0025_queue_operations_metrics.sql','tests/queue_operations.php','tests/queue_operations_database.php','docs/Queue-Operations-Observability.md',
    'core/Queue/QueueFairness.php','migrations/pgsql/0024_project_queue_fairness.sql','tests/queue_fairness.php','tests/queue_fairness_database.php','docs/Multi-Project-Queue-Fairness.md',
    'core/Queue/BackpressurePolicy.php','migrations/pgsql/0023_queue_backpressure.sql','tests/queue_backpressure.php','tests/queue_backpressure_database.php','docs/Backpressure-Admission-Control.md',
    'core/Queue/DeadLetterManager.php','public/admin/assets/pages/queue.js','tests/dead_letter_database.php','docs/Dead-Letter-Management.md',
    'tests/throughput_planner.php','docs/High-Throughput-0.6.0.md',
    'tests/worker_microbatch.php','tests/worker_microbatch_database.php','docs/High-Throughput-Batch-Ingestion.md',
    'core/Queue/RetryPolicy.php','tests/retry_policy.php','docs/Retry-Failure-Recovery.md',
    'core/Queue/WorkerPolicy.php','core/Queue/WorkerRegistry.php','core/Queue/WorkerPool.php','tests/worker_runtime.php','docs/Worker-Operations.md',
    'core/Database/SecuritySchema.php', 'core/Operations/OperationsCheck.php', 'docs/Security-Operations-Stable.md',
    'core/Observability/AuditFailureMetrics.php','core/Observability/WorkerHeartbeat.php','core/Observability/WorkerMetrics.php','core/Observability/Readiness.php','tests/health_monitoring.php','tests/monitoring_config.py','tests/monitoring_stack.py','deploy/monitoring/prepare.py','deploy/monitoring/compose.yaml','deploy/monitoring/alerts.yml','deploy/monitoring/alerts.test.yml','deploy/monitoring/grafana/provisioning/datasources/prometheus.yaml','deploy/monitoring/grafana/provisioning/dashboards/cajeer.yaml','deploy/monitoring/grafana/dashboards/operations.json','docs/Prometheus.md',
    'core/Operations/BackupVault.php', 'core/Operations/BackupOperations.php', 'tests/backup_operations.php', 'docs/Backup-Disaster-Recovery.md', 
    'migrations/pgsql/0022_queue_accounting.sql', 'core/Queue/QueueAccounting.php', 'docs/PostgreSQL-Queue-Hardening.md', 'migrations/pgsql/0021_retention_execution_history.sql', 'docs/Retention-Storage-Lifecycle.md', 'migrations/pgsql/0020_rate_limit_buckets.sql', 'core/Security/RateLimitPolicy.php', 'tests/rate_limit_database.php', 'docs/Rate-Limiting.md',
    'migrations/pgsql/0019_audit_accountability.sql', 'core/Audit/AuditLogger.php', 'tests/audit_database.php', 'docs/Audit-Log-Accountability.md',
    'migrations/pgsql/0015_authentication_sessions.sql',
    'migrations/pgsql/0016_saved_log_views.sql',
    'migrations/pgsql/0018_identity_security.sql', 'core/Security/IdentityPolicy.php', 'tests/identity_security_database.php', 'docs/Identity-2FA-Security.md',
    'migrations/pgsql/0017_rbac_roles_permissions.sql', 'public/admin/assets/pages/rbac.js', 'tests/rbac_database.php', 'docs/RBAC.md',
    'core/Log/SavedView.php',
    'public/admin/assets/app/filters.js',
    'public/admin/assets/app/source-connection.js',
    'public/admin/assets/components/source-onboarding.js',
    'docs/Project-Source-Management.md',
    'docs/Token-Management-Security.md',
    'public/admin/assets/components/filter-builder.js',
    'public/admin/assets/components/saved-views.js',
    'docs/Advanced-Filters-Search.md',
    'public/admin/assets/components/session-list.js',
    'public/admin/assets/components/qr-encoder.js',
    'docs/Authentication-Sessions.md',
    'public/admin/assets/api/client.js',
    'public/admin/assets/app/analytics.js',
    'public/admin/assets/app/explorer.js',
    'docs/Log-Explorer.md',
    'docs/Dashboard-Analytics.md',
    'public/admin/assets/app/projects.js',
    'public/admin/assets/app/router.js',
    'public/admin/assets/app/routes.js',
    'public/admin/assets/app/session.js',
    'public/admin/assets/app/shell.js',
    'public/admin/assets/app/store.js',
    'public/admin/assets/app/theme.js',
    'public/admin/assets/components/log-table.js',
    'public/admin/assets/components/event-inspector.js',
    'public/admin/assets/app/event-json.js',
    'docs/Event-Details-JSON-Viewer.md',
    'public/admin/assets/components/mutations.js',
    'public/admin/assets/components/pagination.js',
    'public/admin/assets/components/ui.js',
    'public/admin/assets/pages/logs.js',
    'public/admin/assets/pages/overview.js',
    'public/admin/assets/pages/projects.js',
    'public/admin/assets/pages/settings.js',
    'public/admin/assets/pages/system.js',
    'public/admin/assets/pages/tokens.js',
    'public/admin/assets/pages/users.js',
    'public/admin/assets/panel.css',
    'public/admin/assets/panel.js',
    'public/admin/assets/styles/base.css',
    'public/admin/assets/styles/components.css',
    'public/admin/assets/styles/layout.css',
    'public/admin/assets/styles/tokens.css',
    'admin/verify.mjs',
    'admin/client.test.mjs',
    'tests/admin_browser.py',
    'tests/admin_browser_database.py',
    'tests/admin_database.php',
    'public/admin/assets/pages/security.js',
    'public/admin/assets/components/workspace-switcher.js',
    'docs/Admin-Panel-Release.md',
})


def digest(data):
    return hashlib.sha256(data).hexdigest()


def allowed(name):
    p = PurePosixPath(name)
    if not name or '\\' in name or name.startswith('/') or str(p) != name or any(x in ('.', '..') for x in p.parts):
        return False
    if len(p.parts) == 1:
        return name in FILES
    if p.parts[0] == 'storage':
        return name in {'storage/.gitkeep', *(f'storage/{d}/.gitkeep' for d in ('app','archives','backups','cache','database','logs','tmp'))}
    if p.parts[0] not in DIRS or any(x in BLOCKED or x.endswith('.egg-info') or x.startswith('.') for x in p.parts[1:] if x not in ('.gitkeep', '.htaccess')):
        return False
    return p.suffix in SUFFIXES or p.name in {'Dockerfile', 'cajeer', '.gitkeep', '.htaccess', 'LICENSE', 'NOTICE'}


def source_files(root):
    result = {}
    # On a checkout, use tracked sources and explicitly required release files.
    # This also supports overlaying a changed-files archive before staging it.
    # Exported archives without .git use the explicit source allowlist.
    if (root / '.git').exists():
        tracked = subprocess.check_output(['git','ls-files','-z'], cwd=root).decode().split('\0')
        paths = [root / n for n in set(tracked) | REQUIRED if n]
    else:
        paths = root.rglob('*')
    for path in sorted(paths):
        name = path.relative_to(root).as_posix()
        if not allowed(name):
            continue
        if path.is_symlink():
            raise ValueError(f'Symlink in release source: {name}')
        if path.is_file():
            result[name] = path.read_bytes()
    # Empty runtime directories are deliberately shipped, never local contents.
    for name in ['storage/.gitkeep', *(f'storage/{d}/.gitkeep' for d in ('app','archives','backups','cache','database','logs','tmp'))]:
        result.setdefault(name, b'')
    validate_payload(result)
    return result


def validate_payload(files):
    if not REQUIRED <= files.keys():
        raise ValueError('Missing release files: ' + ', '.join(sorted(REQUIRED - files.keys())))
    version = json.loads(files['release.json'])['version']
    if not re.fullmatch(r'\d+\.\d+\.\d+', version):
        raise ValueError('Invalid public release version')
    if json.loads(files['admin/package.json'])['version'] != version or files['openapi.yaml'] != files['api/openapi.yaml'] or json.loads(files['api/openapi.yaml'])['info']['version'] != version:
        raise ValueError('Release, admin and API versions must agree')
    if any(json.loads(files[name])['version'] != version for name in ('packages/php-sdk/composer.json','packages/ts-sdk/package.json')):
        raise ValueError('Release and SDK versions must agree')
    # Keep all four client package versions synchronized, not only the API and UI.
    if not re.search(r'^version\s*=\s*\"'+re.escape(version)+r'\"\s*$', files['clients/python/pyproject.toml'].decode(), re.M) or not re.search(r'^version\s*=\s*\"'+re.escape(version)+r'\"\s*$', files['clients/java-kotlin/build.gradle.kts'].decode(), re.M):
        raise ValueError('Release and client versions must agree')
    migrations = sorted(n for n in files if n.startswith('migrations/pgsql/') and n.endswith('.sql'))
    if not migrations or any(not re.fullmatch(rf'migrations/pgsql/{i:04d}_[a-z0-9_]+\.sql', n) for i, n in enumerate(migrations, 1)):
        raise ValueError('Non-sequential PostgreSQL migrations')
    return version


def manifest(files):
    return {'format': 1, 'name': 'CajeerLogs', 'version': validate_payload(files), 'files': {
        name: {'bytes': len(data), 'sha256': digest(data)} for name, data in sorted(files.items())}}


def mode(name):
    return 0o755 if name == 'bin/cajeer' or name.endswith('.sh') else 0o644


def build(root, output):
    files = source_files(root)
    meta = manifest(files)
    files[MANIFEST] = (json.dumps(meta, indent=2, sort_keys=True) + '\n').encode()
    output.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(dir=output.parent, prefix='.release-')
    os.close(fd)
    try:
        with zipfile.ZipFile(temp, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
            for name, data in sorted(files.items()):
                info = zipfile.ZipInfo(name, (1980,1,1,0,0,0))
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | mode(name)) << 16
                z.writestr(info, data, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        verify(Path(temp))
        os.replace(temp, output)
        output.chmod(0o644)
        output.with_name(output.name + '.sha256').write_text(f'{digest(output.read_bytes())}  {output.name}\n')
    finally:
        Path(temp).unlink(missing_ok=True)
    return meta


def verify(archive, extract=None):
    with zipfile.ZipFile(archive) as z:
        infos = z.infolist()
        names = [i.filename for i in infos]
        if len(names) != len(set(names)) or MANIFEST not in names:
            raise ValueError('Duplicate entries or missing release manifest')
        if sum(i.file_size for i in infos) > 100 * 1024 * 1024:
            raise ValueError('Release exceeds size limit')
        for i in infos:
            if (i.filename != MANIFEST and not allowed(i.filename)) or i.is_dir() or stat.S_IFMT(i.external_attr >> 16) not in (0, stat.S_IFREG):
                raise ValueError(f'Unsafe release entry: {i.filename}')
        files = {n: z.read(n) for n in names}
    meta = json.loads(files.pop(MANIFEST))
    if meta != manifest(files):
        raise ValueError('Release integrity check failed')
    if extract is not None:
        if extract.is_symlink() or (extract.exists() and (not extract.is_dir() or any(extract.iterdir()))):
            raise ValueError('Extraction target must be an empty directory')
        extract.mkdir(parents=True, exist_ok=True)
        files[MANIFEST] = (json.dumps(meta, indent=2, sort_keys=True) + '\n').encode()
        for name, data in files.items():
            target = extract / name
            target.parent.mkdir(parents=True, exist_ok=True)
            with target.open('xb') as f:
                f.write(data)
            target.chmod(mode(name))
    return meta


def main():
    p = argparse.ArgumentParser(description=__doc__)
    sub = p.add_subparsers(dest='command', required=True)
    b = sub.add_parser('build'); b.add_argument('output', type=Path)
    v = sub.add_parser('verify'); v.add_argument('archive', type=Path); v.add_argument('--extract', type=Path)
    args = p.parse_args()
    try:
        meta = build(ROOT, args.output) if args.command == 'build' else verify(args.archive, args.extract)
    except (ValueError, OSError, KeyError, zipfile.BadZipFile) as e:
        p.exit(1, f'Release failure: {e}\n')
    print(f"CajeerLogs {meta['version']}: {len(meta['files'])} files verified")


if __name__ == '__main__':
    main()

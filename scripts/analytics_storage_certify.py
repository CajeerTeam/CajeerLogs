#!/usr/bin/env python3
"""Run actual analytics/storage recovery, migration and load-regression stages
from the verified release archive, then write SHA-256 bound CI evidence.

No dry-run, mocks in place of PostgreSQL/ClickHouse, or production credentials.
The S3 emulator accepts actual signed HTTP requests and injects transport faults.
It is used for deterministic fault tests; separate real S3 acceptance remains
necessary before deploying to an object store.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

import release

VERSION = '1.0.9'
STAGES = (
    ('postgres-migration', ('php', 'tests/migration_engine_database.php')),
    ('rollup-reconciliation', ('php', 'tests/analytics_rollups_database.php')),
    ('replication-replay', ('php', 'tests/analytics_replication_database.php')),
    ('service-stop-and-recovery', (sys.executable, 'scripts/failure_recovery.py')),
    ('storage-fault-recovery', (sys.executable, 'tests/s3_storage.py')),
)


def atomic_json(path: Path, value: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, prefix='.storage-cert-',
                                     encoding='utf-8', delete=False) as stream:
        tmp = Path(stream.name)
        try:
            json.dump(value, stream, indent=2, sort_keys=True)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        except BaseException:
            tmp.unlink(missing_ok=True)
            raise
    os.replace(tmp, path)


def prerequisites(env: dict) -> None:
    if env.get('CAJEER_TEST_DATABASE') != '1' or env.get('CAJEER_TEST_CLICKHOUSE') != '1' or env.get('APP_ENV') != 'test':
        raise ValueError('Explicit disposable PostgreSQL and ClickHouse opt-in is required')
    if not all(env.get(k) for k in ('DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
                                    'CLICKHOUSE_DSN', 'CLICKHOUSE_DATABASE', 'CLICKHOUSE_USERNAME',
                                    'CLICKHOUSE_PASSWORD')):
        raise ValueError('Missing explicit test database credentials')
    if env['DB_DATABASE'].lower() in ('postgres', 'template0', 'template1'):
        raise ValueError('Refusing a PostgreSQL system database')


def runtime_probe(root: Path, env: dict) -> dict:
    php = r'''$a=require "core/bootstrap.php";$a->boot();
    $p=$a->container()->get(PDO::class);
    $c=$a->container()->get(\Cajeer\Logs\Analytics\ClickHouseHttpClient::class);
    $rows=$c->select("SELECT version() AS version");
    echo json_encode(["php"=>PHP_VERSION,"postgres"=>$p->query("SHOW server_version_num")->fetchColumn(),
       "clickhouse"=>$rows[0]["version"]??null,"db_owner"=>$p->query("SELECT current_user=pg_get_userbyid(datdba) FROM pg_database WHERE datname=current_database()")->fetchColumn()],JSON_THROW_ON_ERROR);'''
    data = json.loads(subprocess.check_output(['php', '-r', php], cwd=root, env=env,
                                              text=True, timeout=30))
    if not (str(data['php']).startswith(('8.4.', '8.5.'))
            and int(data['postgres']) >= 170000
            and isinstance(data['clickhouse'], str) and data['clickhouse']
            and data['db_owner'] in (True, 't', '1', 1)):
        raise ValueError('Actual database or PHP version/ownership does not match certification profile')
    return {'php': data['php'], 'postgres': str(data['postgres']), 'clickhouse': data['clickhouse']}


def run(archive: Path, output: Path) -> bool:
    info = release.verify(archive)
    if info['version'] != VERSION:
        raise ValueError('Expected release 1.0.9')
    report = {
        'format': 1, 'scope': 'analytics-storage-e2e', 'version': VERSION,
        'archive_sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
        'created_at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'status': 'failed', 'stages': [],
    }
    try:
        env = dict(os.environ)
        prerequisites(env)
        env.pop('CAJEER_ENV_FILE', None)
        env.pop('DB_MIGRATION_USERNAME', None)
        env.pop('DB_MIGRATION_PASSWORD', None)
        env['CAJEER_TEST_INTEGRATED_STORAGE'] = '1'
        with tempfile.TemporaryDirectory(prefix='cajeer-storage-e2e-') as tmp:
            root = Path(tmp) / 'release'
            release.verify(archive, root)
            report['runtime'] = runtime_probe(root, env)
            for name, command in STAGES:
                stage = {'name': name, 'status': 'failed', 'checks': 0}
                report['stages'].append(stage)
                began = time.monotonic()
                try:
                    process = subprocess.run(command, cwd=root, env=env, text=True,
                                             stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                             timeout=900, check=False)
                    stage['exit_code'] = process.returncode
                    lines = process.stdout.splitlines()
                    stage['checks'] = sum(line.startswith('PASS ') for line in lines)
                    if name == 'service-stop-and-recovery':
                        records = [json.loads(line[len('RECOVERY_JSON:'):])
                                   for line in lines if line.startswith('RECOVERY_JSON:')]
                        if len(records) != 1 or stage['checks'] < 12:
                            diagnostic = (process.stderr + '\n' + process.stdout)[-2000:]
                            for key in ('DB_PASSWORD', 'CLICKHOUSE_PASSWORD', 'PGPASSWORD', 'APP_KEY'):
                                secret = env.get(key, '')
                                if len(secret) >= 3:
                                    diagnostic = diagnostic.replace(secret, '[REDACTED]')
                            stage['diagnostic'] = diagnostic
                            raise RuntimeError('Missing real service stop/start and recovery checks: ' + diagnostic)
                        stage['recovery'] = records[0]
                    if name == 'storage-fault-recovery':
                        metrics = [json.loads(line[len('PERFORMANCE_JSON:'):])
                                   for line in lines if line.startswith('PERFORMANCE_JSON:')]
                        if len(metrics) != 1:
                            raise RuntimeError('Missing measured analytics/storage workload')
                        stage['performance'] = metrics[0]
                    if process.returncode != 0 or stage['checks'] == 0:
                        raise RuntimeError('Failed analytics/storage stage: ' + name)
                    stage['status'] = 'passed'
                    print(f'PASS {name}: {stage["checks"]} real checks', flush=True)
                finally:
                    stage['seconds'] = round(time.monotonic() - began, 3)
            report['status'] = 'passed'
    except (Exception, KeyboardInterrupt) as exc:
        report['error_type'] = type(exc).__name__
        diagnostic = str(exc)
        for key in ('DB_PASSWORD', 'DB_MIGRATION_PASSWORD', 'CLICKHOUSE_PASSWORD',
                    'PGPASSWORD', 'AWS_SECRET_ACCESS_KEY', 'APP_KEY'):
            secret = os.environ.get(key, '')
            if len(secret) >= 3:
                diagnostic = diagnostic.replace(secret, '[REDACTED]')
        report['error_message'] = diagnostic[-1200:]
        print('Analytics/storage certification failed: ' + report['error_message'], file=sys.stderr)
    finally:
        atomic_json(output, report)
    return report['status'] == 'passed'


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    try:
        return 0 if run(args.archive.resolve(), args.output.resolve()) else 1
    except (Exception, KeyboardInterrupt) as exc:
        # Even a malformed candidate cannot produce a successful certificate.
        atomic_json(args.output.resolve(), {
            'format': 1, 'scope': 'analytics-storage-e2e', 'version': VERSION,
            'status': 'failed', 'error_type': type(exc).__name__, 'stages': []})
        return 1


if __name__ == '__main__':
    sys.exit(main())

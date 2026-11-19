#!/usr/bin/env python3
"""CajeerLogs 0.8.6 release gate, including live load/fault smoke. Executes real tests on a verified distribution.

Database: explicit disposable PostgreSQL credentials and two empty restore targets.
Admin: real browser/PHP/PostgreSQL E2E on the exact archive.
Release: four trusted CI database reports and admin evidence, then real Docker smoke.
No report is a signature or an independent security certification.
"""
import argparse
import datetime
import hashlib
import json
import os
import signal
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time

import release
import supply_chain
from analytics_storage_certify import STAGES as ANALYTICS_STORAGE_STAGES
from integration_stability import STAGES as STABILITY_STAGES

VERSION = '0.8.6'
DATABASE_TESTS = (
    'database', 'migration_engine_database', 'migration_scale_database', 'transaction_database',
    'identity_database', 'identity_security_database', 'audit_database', 'rate_limit_database', 'rbac_database', 'ingest_database', 'delivery_database', 'hmac_database', 'redaction_database', 'search_database',
    'log_repository_database', 'error_analytics_database', 'analytics_rollups_database', 'webhook_database', 'webhook_subscriptions_database', 'export_jobs_database', 'queue_database', 'dead_letter_database','worker_microbatch_database', 'queue_backpressure_database','queue_fairness_database','queue_operations_database',
    'projects_database', 'sources_database', 'source_monitoring_database', 'tokens_database',
    'integrity_database', 'security_database',
)
STAGES = tuple(DATABASE_TESTS) + ('pipeline', 'pipeline-repeat', 'aapanel-integration', 'upgrade-0.1.0', 'upgrade-0.2.0', 'upgrade-0.2.9', 'upgrade-0.3.0', 'upgrade-0.3.2', 'upgrade-0.3.3', 'upgrade-0.3.4', 'upgrade-0.3.5', 'upgrade-0.3.6', 'upgrade-0.3.7', 'upgrade-0.3.8', 'upgrade-0.3.9', 'upgrade-0.4.0', 'upgrade-0.4.1', 'upgrade-0.4.2', 'upgrade-0.4.3','upgrade-0.4.4','upgrade-0.4.5', 'upgrade-0.4.6', 'upgrade-0.4.7','upgrade-0.4.8','upgrade-0.4.9','upgrade-0.4.10','upgrade-0.5.0','upgrade-0.5.1','upgrade-0.5.2','upgrade-0.5.3','upgrade-0.5.4','upgrade-0.5.5','upgrade-0.5.6','upgrade-0.5.7','upgrade-0.5.8','upgrade-0.5.9','upgrade-0.5.10','upgrade-0.6.0','upgrade-0.6.1','upgrade-0.6.2','upgrade-0.6.3','upgrade-0.6.4','upgrade-0.6.5','upgrade-0.6.6','upgrade-0.6.7','upgrade-0.6.8','upgrade-0.6.9','upgrade-0.7.9','upgrade-0.8.0','upgrade-0.8.1','upgrade-0.8.2','upgrade-0.8.3','upgrade-0.8.4', 'upgrade-0.8.5')
ADMIN_STAGES = ('release-runtime','http-runtime','health-monitoring','worker-runtime','worker-microbatch','throughput-planner','queue-backpressure','queue-fairness','queue-operations','retry-policy','monitoring-config','backup-operations', 'secure-installer', 'deployment-policy', 'deployment-tls', 'security-hardening', 'frontend-build', 'browser-ui-chromium', 'browser-db-chromium', 'browser-db-firefox', 'browser-db-webkit')
MATRIX = {(php, pg) for php in ('8.4', '8.5') for pg in ('17', '18')}


def archive_info(path):
    meta = release.verify(path)
    if meta['version'] != VERSION:
        raise ValueError('Expected the 0.8.6 release archive')
    return {'version': VERSION, 'archive_sha256': hashlib.sha256(path.read_bytes()).hexdigest()}


def write_report(path, report):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(prefix='.certify-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as f:
            json.dump(report, f, indent=2, sort_keys=True)
            f.write('\n'); f.flush(); os.fsync(f.fileno())
        os.replace(tmp, path)
    finally:
        Path(tmp).unlink(missing_ok=True)


def run_stage(report, name, cmd, root, env, timeout=900):
    """Run an actual integration stage; kill its whole group if it times out.

    A timed-out test may leave PHP HTTP servers, queue workers or database
    sessions behind. Subsequent stages must never observe that leaked state.
    """
    start = time.monotonic()
    print('RUN', name, flush=True)
    stage = {'name': name, 'status': 'failed'}
    report['stages'].append(stage)
    process = None
    try:
        process = subprocess.Popen(cmd, cwd=root, env=env, start_new_session=True)
        try:
            code = process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            code = 124
        stage['exit_code'] = code
        if code != 0:
            raise RuntimeError(f'{name} failed (exit {code})')
        stage['status'] = 'passed'
    finally:
        if process is not None:
            # The process has a private session; signal leftover descendants,
            # not any other job's processes or the runner itself.
            try:
                os.killpg(process.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                process.wait(timeout=5)
        stage['seconds'] = round(max(0.000001, time.monotonic() - start), 3)


def database_environment():
    env = os.environ.copy()
    if env.get('CAJEER_TEST_DATABASE') != '1' or env.get('APP_ENV') != 'test':
        raise ValueError('Set CAJEER_TEST_DATABASE=1 and APP_ENV=test for a disposable database')
    required = ('DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD','CAJEER_TEST_RESTORE_DB','CAJEER_TEST_UPGRADE_RESTORE_DB','CAJEER_TEST_UPGRADE_ROLLBACK_DB')
    if any(not env.get(k) for k in required):
        raise ValueError('Explicit DB credentials and two restore databases are required')
    if len({env['DB_DATABASE'],env['CAJEER_TEST_RESTORE_DB'],env['CAJEER_TEST_UPGRADE_RESTORE_DB'], env['CAJEER_TEST_UPGRADE_ROLLBACK_DB']}) != 4:
        raise ValueError('Use four distinct disposable databases')
    for name in ('php','psql'):
        if not shutil.which(name): raise ValueError(f'Missing executable: {name}')
    for name in ('PG_DUMP_BIN','PG_RESTORE_BIN'):
        if not env.get(name) or not Path(env[name]).is_file() or not os.access(env[name], os.X_OK):
            raise ValueError(f'Explicit executable {name} is required')
    # Never load a real installation's config or elevated migration credentials.
    for name in ('CAJEER_ENV_FILE','DB_MIGRATION_USERNAME','DB_MIGRATION_PASSWORD'):
        env.pop(name,None)
    env.update(APP_DEBUG='false', DB_SCHEMA='public', APP_URL='http://localhost:8080')
    return env


def probe_runtime(root, env):
    # Read real versions through the application connection; never trust CI labels.
    probe = '''$a=require "core/bootstrap.php"; $a->boot();
    $p=$a->container()->get(PDO::class);
    echo json_encode(["php"=>PHP_VERSION,"postgres"=>$p->query("SHOW server_version")->fetchColumn(),
    "postgres_num"=>(int)$p->query("SHOW server_version_num")->fetchColumn(),
    "owner"=>$p->query("SELECT current_user=pg_get_userbyid(datdba) FROM pg_database WHERE datname=current_database()")->fetchColumn()]);'''
    versions = json.loads(subprocess.check_output(['php','-r',probe],cwd=root,env=env,text=True,timeout=30))
    php = '.'.join(versions['php'].split('.')[:2]); pg = str(versions['postgres_num']//10000)
    if (php,pg) not in MATRIX or versions['owner'] is not True:
        raise ValueError('Expected PHP 8.4/8.5, PostgreSQL 17/18 and an owner of the disposable test database')
    return dict(php=versions['php'],postgres=versions['postgres'],php_series=php,postgres_major=pg)


def database(args, report):
    env = database_environment()
    previous = {'0.1.0': args.from_010.resolve(), '0.2.0': args.from_020.resolve(), '0.2.9': args.from_029.resolve(), '0.3.0': args.from_030.resolve(), '0.3.2': args.from_032.resolve(), '0.3.3': args.from_033.resolve(), '0.3.4': args.from_034.resolve(), '0.3.5': args.from_035.resolve(), '0.3.6': args.from_036.resolve(), '0.3.7': args.from_037.resolve(), '0.3.8': args.from_038.resolve(), '0.3.9': args.from_039.resolve(), '0.4.0': args.from_040.resolve(), '0.4.1': args.from_041.resolve(), '0.4.2': args.from_042.resolve(), '0.4.3': args.from_043.resolve(), '0.4.4': args.from_044.resolve(), '0.4.5': args.from_045.resolve(), '0.4.6': args.from_046.resolve(), '0.4.7': args.from_047.resolve(),'0.4.8': args.from_048.resolve(),'0.4.9': args.from_049.resolve(),'0.4.10': args.from_0410.resolve(),'0.5.0': args.from_050.resolve(),'0.5.1': args.from_051.resolve(),'0.5.2': args.from_052.resolve(),'0.5.3': args.from_053.resolve(),'0.5.4': args.from_054.resolve(),'0.5.5': args.from_055.resolve(), '0.5.6': args.from_056.resolve(), '0.5.7': args.from_057.resolve(), '0.5.8': args.from_058.resolve(), '0.5.9': args.from_059.resolve(), '0.5.10': args.from_0510.resolve(), '0.6.0': args.from_060.resolve(), '0.6.1': args.from_061.resolve(), '0.6.2': args.from_062.resolve(), '0.6.3': args.from_063.resolve(), '0.6.4': args.from_064.resolve(), '0.6.5': args.from_065.resolve(), '0.6.6': args.from_066.resolve(), '0.6.7': args.from_067.resolve(), '0.6.8': args.from_068.resolve(), '0.6.9': args.from_069.resolve(), '0.7.9': args.from_079.resolve(), '0.8.0': args.from_080.resolve(), '0.8.1': args.from_081.resolve(), '0.8.2': args.from_082.resolve(), '0.8.3': args.from_083.resolve(), '0.8.4': args.from_084.resolve(), '0.8.5': args.from_085.resolve()}
    for version,path in previous.items():
        if json.loads((path/'release.json').read_text())['version'] != version:
            raise ValueError(f'Expected actual source tree {version}')
    with tempfile.TemporaryDirectory(prefix='cl-core-certify-') as tmp:
        root = Path(tmp)/'app'; release.verify(args.archive,root)
        report.update(probe_runtime(root,env))
        env['CAJEER_MIGRATION_CERT_REPORT']=str(Path(tmp)/'migration-scale.json')
        env['CAJEER_MIGRATION_CERT_ARCHIVE_SHA256']=report['archive_sha256']
        for name in DATABASE_TESTS:
            run_stage(report,name,['php',f'tests/{name}.php'],root,env)
        report['migration_scale']=json.loads(Path(env['CAJEER_MIGRATION_CERT_REPORT']).read_text())
        # The nested HTTP pipeline and upgrades MUST run the exact externally verified ZIP.
        # Fresh schemas per pass catch state leaks, duplicate ids and unsafe cleanup.
        proof_dir=Path(tmp)/'upgrade-evidence'; proof_dir.mkdir(mode=0o700)
        env['CAJEER_TEST_UPGRADE_CERT_DIR']=str(proof_dir)
        env['CAJEER_TEST_RELEASE_ARCHIVE'] = str(args.archive.resolve())
        env['CAJEER_TEST_REQUIRE_ARCHIVE'] = '1'
        run_stage(report,'pipeline',[sys.executable,'tests/pipeline_database.py'],root,env)
        run_stage(report,'pipeline-repeat',[sys.executable,'tests/pipeline_database.py'],root,env)
        run_stage(report,'aapanel-integration',[sys.executable,'tests/aapanel_integration_stable.py'],root,env)
        report['upgrade_rollback']={}
        for version,path in previous.items():
            run_stage(report,'upgrade-'+version,[sys.executable,'tests/upgrade_release.py',str(path)],root,env)
            evidence=json.loads((proof_dir/(version+'.json')).read_text())
            validate_upgrade_rollback(evidence,report,version)
            report['upgrade_rollback'][version]=evidence


UPGRADE_CHECKS = ('old_install','preupgrade_backup','candidate_integrity','candidate_api',
                  'candidate_queue','candidate_restore','previous_binary_restarted',
                  'original_database_restored','rollback_auth_and_ingest',
                  'rollback_queue_processed','upgraded_database_untouched')


def validate_upgrade_rollback(proof, info, previous):
    if (not isinstance(proof,dict) or proof.get('format') != 1 or proof.get('scope') != 'upgrade-rollback'
        or proof.get('status') != 'passed' or proof.get('from_version') != previous
        or proof.get('to_version') != info['version'] or proof.get('archive_sha256') != info['archive_sha256']
        or not isinstance(proof.get('backup_sha256'),str)
        or len(proof['backup_sha256'])!=64 or any(c not in '0123456789abcdef' for c in proof['backup_sha256'])
        or proof.get('checks') != list(UPGRADE_CHECKS)
        or not isinstance(proof.get('pending_migrations'),list)
        or any(not isinstance(name,str) or not __import__('re').fullmatch(r'\d{4}_[a-z0-9_]+\.sql',name) for name in proof['pending_migrations'])
        or len(proof['pending_migrations'])!=len(set(proof['pending_migrations']))
        or proof['pending_migrations']!=sorted(proof['pending_migrations'])):
        raise ValueError('Invalid actual upgrade/rollback proof for '+previous)


def validate_matrix(reports, info):
    seen=set()
    for r in reports:
        pair=(r.get('php_series'),r.get('postgres_major'))
        if (r.get('scope')!='database-core' or r.get('status')!='passed' or r.get('format')!=1
            or r.get('version')!=info['version'] or r.get('archive_sha256')!=info['archive_sha256']
            or pair not in MATRIX or pair in seen):
            raise ValueError('Invalid, duplicate or mismatched database certification report')
        stages=r.get('stages',[])
        if [s.get('name') for s in stages]!=list(STAGES) or any(s.get('status')!='passed' or s.get('exit_code')!=0 for s in stages):
            raise ValueError('Missing or failed mandatory database test')
        proofs=r.get('upgrade_rollback')
        versions=[name.removeprefix('upgrade-') for name in STAGES if name.startswith('upgrade-')]
        if not isinstance(proofs,dict) or set(proofs)!=set(versions):
            raise ValueError('Incomplete upgrade/rollback certification evidence')
        for version in versions:
            validate_upgrade_rollback(proofs[version],info,version)
        if not str(r.get('php','')).startswith(pair[0]+'.') or not str(r.get('postgres','')).startswith(pair[1]+'.'):
            raise ValueError('Database runtime version mismatch')
        scale=r.get('migration_scale')
        if (not isinstance(scale,dict) or scale.get('version') != VERSION
            or scale.get('status') != 'passed' or scale.get('archive_sha256') != info['archive_sha256']
            or scale.get('test_rows') != 100000 or scale.get('backfill_checksum') != '5000050000'
            or not isinstance(scale.get('checks'),int) or scale['checks'] < 20
            or not str(scale.get('postgresql_version','')).startswith(pair[1]+'.')):
            raise ValueError('Missing or invalid large-database migration certification')
        seen.add(pair)
    if seen!=MATRIX:
        raise ValueError('All four PHP/PostgreSQL combinations are mandatory')


def admin_panel(args, report):
    if os.environ.get('CAJEER_BROWSER_OFFLINE') == '1':
        raise ValueError('Offline browser mode cannot certify the admin panel')
    env = database_environment()
    env.pop('CAJEER_BROWSER_OFFLINE', None)
    env.pop('CHROMIUM_PATH', None)
    if not shutil.which('npm'):
        raise ValueError('npm is required to verify the production frontend')
    with tempfile.TemporaryDirectory(prefix='cl-admin-certify-') as tmp:
        root=Path(tmp)/'app'; release.verify(args.archive,root)
        report.update(probe_runtime(root,env))
        if (report['php_series'],report['postgres_major'])!=('8.4','17'):
            raise ValueError('Admin certification requires the PHP 8.4 / PostgreSQL 17 reference environment')
        run_stage(report,'release-runtime',['php','tests/release_runtime.php'],root,env)
        run_stage(report,'http-runtime',[sys.executable,'tests/http_runtime.py'],root,env)
        run_stage(report,'health-monitoring',['php','tests/health_monitoring.php'],root,env)
        run_stage(report,'worker-runtime',['php','tests/worker_runtime.php'],root,env)
        run_stage(report,'worker-microbatch',['php','tests/worker_microbatch.php'],root,env)
        run_stage(report,'throughput-planner',['php','tests/throughput_planner.php'],root,env)
        run_stage(report,'queue-backpressure',['php','tests/queue_backpressure.php'],root,env)
        run_stage(report,'queue-fairness',['php','tests/queue_fairness.php'],root,env)
        run_stage(report,'queue-operations',['php','tests/queue_operations.php'],root,env)
        run_stage(report,'retry-policy',['php','tests/retry_policy.php'],root,env)
        run_stage(report,'monitoring-config',[sys.executable,'tests/monitoring_config.py'],root,env)
        run_stage(report,'backup-operations',['php','tests/backup_operations.php'],root,env)
        run_stage(report,'secure-installer',['php','tests/secure_installer.php'],root,env)
        run_stage(report,'deployment-policy',['php','tests/deployment_security.php'],root,env)
        run_stage(report,'deployment-tls',[sys.executable,'tests/deployment_tls.py'],root,env)
        run_stage(report,'security-hardening',['php','tests/security_hardening.php'],root,env)
        run_stage(report,'frontend-build',['npm','--prefix','admin','run','build'],root,env)
        run_stage(report,'browser-ui-chromium',[sys.executable,'tests/admin_browser.py'],root,dict(env,CAJEER_BROWSER_ENGINE='chromium'))
        for engine in ('chromium','firefox','webkit'):
            run_stage(report,'browser-db-'+engine,[sys.executable,'tests/admin_browser_database.py'],root,dict(env,CAJEER_BROWSER_ENGINE=engine))


def validate_admin(r, info):
    if (r.get('scope')!='admin-panel' or r.get('format')!=1 or r.get('status')!='passed'
        or r.get('version')!=info['version'] or r.get('archive_sha256')!=info['archive_sha256']):
        raise ValueError('Invalid or mismatched admin certification report')
    if (r.get('php_series'),r.get('postgres_major'))!=('8.4','17') or not str(r.get('php','')).startswith('8.4.') or not str(r.get('postgres','')).startswith('17.'):
        raise ValueError('Invalid admin runtime version evidence')
    stages=r.get('stages',[])
    if [s.get('name') for s in stages]!=list(ADMIN_STAGES) or any(s.get('status')!='passed' or s.get('exit_code')!=0 for s in stages):
        raise ValueError('Missing or failed mandatory admin stage')


def validate_performance(evidence, info):
    """Require complete measurement evidence from the live HTTPS/PostgreSQL runner.

    The report is generated by the shipped live-load process in production_smoke;
    validation checks accounting and mandatory gates, not just a 'passed' string.
    """
    if (evidence.get('format') != 1 or evidence.get('scope') != 'load-performance' or
        evidence.get('version') != info['version'] or
        evidence.get('archive_sha256') != info['archive_sha256'] or
        evidence.get('status') != 'passed' or evidence.get('certified') is not True or
        evidence.get('certification_requested') is not True or
        evidence.get('readiness_confirmed') is not True):
        raise ValueError('Missing valid live throughput certification')
    phases=evidence.get('phases')
    if not isinstance(phases,list) or [p.get('scenario') for p in phases] != ['sustained','stress','soak','fault']:
        raise ValueError('Missing or reordered live throughput phases')
    required={'completion_accounting','achieved_rate','durable_consistency','error_rate',
              'p95_latency','offered_rate','run_duration','per_step_offered_rate',
              'minute_p95_latency','concurrent_search'}
    durations=(60,120,900,60)
    markers=set()
    for phase,duration in zip(phases,durations):
        marker=phase.get('marker')
        gates=phase.get('gates')
        if (not isinstance(marker,str) or not marker.startswith('perf-') or marker in markers or
            phase.get('status')!='passed' or phase.get('duration_target_seconds',0)<duration or
            phase.get('admission_seconds',0)<duration*.98 or
            not isinstance(gates,dict) or not required.issubset(gates.keys()) or
            any(value is not True for value in gates.values()) or
            phase.get('async_jobs',0)<1 or phase.get('durable_events',0)<1 or
            phase.get('durable_events')!=phase.get('verified_events') or
            phase.get('completed_requests',0)!=phase.get('scheduled_requests',-1) or
            phase.get('scheduled_requests',0)<1 or
            phase.get('throughput_requests_per_second',0)<=0 or
            phase.get('effective_ingest_rps',0)<=0 or
            not isinstance(phase.get('errors'),dict) or
            not isinstance(phase.get('latency'),dict) or
            phase['latency'].get('count',0)<1 or
            not isinstance(phase.get('step_schedule'),list) or
            len(phase['step_schedule'])!=(3 if phase['scenario']=='stress' else 1) or
            not isinstance(phase.get('minute_latency'),list) or
            not phase['minute_latency'] or
            not isinstance(phase.get('concurrent_search_latency'),dict) or
            phase['concurrent_search_latency'].get('count',0)<1 or
            phase.get('concurrent_search_errors')):
            raise ValueError('Live queue throughput, accounting or SLO evidence failed')
        if phase['scenario']=='fault' and not {'faults_exercised','ambiguous_commit_recovered'}.issubset(gates):
            raise ValueError('Fault recovery gate missing')
        markers.add(marker)
    fault=phases[-1].get('fault_injection',{})
    if not isinstance(fault,dict) or any(fault.get(k,0)<1 for k in
        ('pre503','pre429','lost_ack','delay','upstream_success')):
        raise ValueError('Mandatory live fault injection was not exercised')
    if phases[-1].get('retry_attempts',0)<1 or phases[-1].get('idempotent_replays',0)<1:
        raise ValueError('Ambiguous commit was not actually recovered')


def validate_stability(report, info):
    """Fail closed unless two fresh extracted release copies ran every HTTP test."""
    if (report.get('format') != 1 or report.get('scope') != 'integration-stability'
        or report.get('status') != 'passed' or report.get('version') != info['version']
        or report.get('archive_sha256') != info['archive_sha256'] or report.get('passes') != 2):
        raise ValueError('Missing or mismatched repeatable integration evidence')
    rows = report.get('stages')
    expected = [(run, name) for run in (1, 2) for name, _ in STABILITY_STAGES]
    if (not isinstance(rows, list) or len(rows) != len(expected)
        or any(not isinstance(row, dict) for row in rows)
        or [(row.get('pass'), row.get('name')) for row in rows] != expected
        or any(row.get('status') != 'passed' or row.get('exit_code') != 0
               or not isinstance(row.get('seconds'), (int, float)) or row['seconds'] <= 0
               or not isinstance(row.get('log_sha256'), str)
               or len(row['log_sha256']) != 64 for row in rows)):
        raise ValueError('Required integration tests did not pass in two isolated copies')


def validate_sdk(report, info):
    from integrations import STAGES
    if (report.get('format') != 1 or report.get('scope') != 'sdk-integrations'
        or report.get('status') != 'passed' or report.get('version') != info['version']
        or report.get('archive_sha256') != info['archive_sha256']):
        raise ValueError('SDK evidence does not match release artifact')
    stages = report.get('stages', [])
    if ([row.get('name') for row in stages] != list(STAGES)
        or any(row.get('status') != 'passed' or row.get('exit_code') != 0 for row in stages)):
        raise ValueError('Incomplete or failed packaged SDK and importer verification')



def validate_analytics_storage(report, info):
    """Require live PG+ClickHouse+S3 evidence from the same verified archive.

    A successful CI dependency is insufficient: each mandatory failure/recovery
    test must have executed and produced PASS assertions, with a matching digest.
    """
    if (report.get('format') != 1 or report.get('scope') != 'analytics-storage-e2e'
        or report.get('version') != info['version']
        or report.get('archive_sha256') != info['archive_sha256']
        or report.get('status') != 'passed'):
        raise ValueError('Missing or mismatched analytics/storage certification report')
    runtime = report.get('runtime')
    if (not isinstance(runtime, dict)
        or not str(runtime.get('php', '')).startswith(('8.4.', '8.5.'))
        or not str(runtime.get('postgres', '')).isdigit()
        or int(runtime['postgres']) < 170000
        or not isinstance(runtime.get('clickhouse'), str)
        or not runtime['clickhouse']):
        raise ValueError('Invalid live analytics/storage runtime evidence')
    stages = report.get('stages')
    if (not isinstance(stages, list)
        or [s.get('name') for s in stages if isinstance(s, dict)] != [name for name, _ in ANALYTICS_STORAGE_STAGES]
        or len(stages) != len(ANALYTICS_STORAGE_STAGES)
        or any(s.get('status') != 'passed' or s.get('exit_code') != 0
               or type(s.get('checks')) is not int or s['checks'] < 1
               or not isinstance(s.get('seconds'), (int, float)) or s['seconds'] < 0
               for s in stages)):
        raise ValueError('Incomplete or unsuccessful analytics/storage fault/recovery checks')
    live_stage = next((stage for stage in stages
                       if stage.get('name') == 'service-stop-and-recovery'), None)
    recovery = live_stage.get('recovery') if live_stage else None
    if (live_stage is None or live_stage.get('checks', 0) < 12 or
        not isinstance(recovery, dict) or
        any(recovery.get(field) is not True for field in
            ('postgres_stopped', 'clickhouse_stopped', 'worker_crash_recovered',
             'replay_verified', 'data_reconciled')) or
        recovery.get('isolated_projects') != 2):
        raise ValueError('Missing evidence for actual stopped services and recovered data')
    measured = stages[-1].get('performance')
    if not isinstance(measured, dict) or measured.get('events') != 300 or measured.get('queries') != 12:
        raise ValueError('Missing real measured analytics/storage workload')
    for key in ('ingest_events_per_second', 'replication_events_per_second',
                'stage_events_per_second', 'cold_events_per_second', 'restore_events_per_second'):
        v = measured.get(key)
        if not isinstance(v, (int, float)) or isinstance(v, bool) or not (0 < v < 1e7):
            raise ValueError('Invalid analytics/storage throughput metric')
    p95 = measured.get('aggregate_p95_ms')
    if not isinstance(p95, (int, float)) or isinstance(p95, bool) or not (0 <= p95 < 1e7):
        raise ValueError('Invalid ClickHouse p95 latency metric')
    for actual, limit in (('ingest_events_per_second', 'min_ingest_events_per_second'),
                          ('replication_events_per_second', 'min_replication_events_per_second')):
        if not isinstance(measured.get(limit), (int, float)) or measured[limit] < 1 or measured[actual] < measured[limit]:
            raise ValueError('Analytics/storage throughput below the required SLO')
    cap = measured.get('max_aggregate_p95_ms')
    if not isinstance(cap, (int, float)) or cap <= 0 or p95 > cap:
        raise ValueError('ClickHouse query p95 above SLO')


def validate_supply_chain_evidence(archive, report, reproduction, info):
    """Recompute all sidecar-derived hashes from verified ZIP; reject synthetic metadata."""
    bom, statement, expected = supply_chain.sidecars(archive)
    for key in ('format', 'scope', 'version', 'archive_sha256',
                'dependency_lock_complete', 'dependency_issues', 'sbom_sha256', 'provenance_sha256'):
        if report.get(key) != expected[key]:
            raise ValueError('Supply-chain report differs from actual verified ZIP')
    if not report['dependency_lock_complete']:
        raise ValueError('Unresolved dependency locks block certified release')
    if (reproduction.get('format') != 1 or reproduction.get('scope') != 'reproducible-build'
        or reproduction.get('status') != 'passed' or reproduction.get('version') != info['version']
        or reproduction.get('archive_sha256') != info['archive_sha256']):
        raise ValueError('Missing verified independent build evidence')
    builds = reproduction.get('independent_builds')
    if (not isinstance(builds,list) or len(builds) != 2
        or any(not isinstance(x,dict) or x.get('index') != i or
               x.get('sha256') != info['archive_sha256'] for i,x in enumerate(builds, 1))):
        raise ValueError('Independent builds do not both match artifact digest')


def final_release(args,report):
    supply_report=json.loads(args.supply_chain_report.read_text())
    rebuild_report=json.loads(args.reproducibility_report.read_text())
    validate_supply_chain_evidence(args.archive,supply_report,rebuild_report,report)
    report['supply_chain_report']=supply_report
    report['reproducibility_report']=rebuild_report
    paths=sorted(args.reports.rglob('database-certification.json'))
    reports=[json.loads(p.read_text()) for p in paths]
    validate_matrix(reports,report)
    report['database_reports']=reports
    admin_report=json.loads(args.admin_report.read_text())
    validate_admin(admin_report,report)
    report['admin_report']=admin_report
    sdk_report=json.loads(args.sdk_report.read_text())
    validate_sdk(sdk_report,report)
    report['sdk_report']=sdk_report
    stability_report=json.loads(args.integration_report.read_text())
    validate_stability(stability_report, report)
    report['integration_stability_report']=stability_report
    storage_report=json.loads(args.analytics_storage_report.read_text())
    validate_analytics_storage(storage_report, report)
    report['analytics_storage_report']=storage_report
    # CI also requires successful TLS/reconnect/pool, split-role, SDK and browser jobs.
    # The release report binds four core DB reports, admin browser evidence and production smoke.
    with tempfile.TemporaryDirectory(prefix='cl-core-production-') as tmp:
        root=Path(tmp)/'app'; release.verify(args.archive,root)
        # This stage operates on real isolated Docker/PG/TLS, then performs
        # all four long async workload phases. No fabricated or short smoke
        # report can certify a stable 0.8.6 release.
        evidence_path=Path(tmp)/'throughput-certification.json'
        smoke_env=dict(os.environ,CAJEER_PERF_ARCHIVE_PATH=str(args.archive.resolve()),
                       CAJEER_PERF_REPORT_PATH=str(evidence_path))
        run_stage(report,'production-and-throughput-certification',[sys.executable,'tests/production_smoke.py'],root,smoke_env,timeout=4500)
        performance=json.loads(evidence_path.read_text())
        validate_performance(performance,report)
        report['performance_report']=performance


def main():
    p=argparse.ArgumentParser(description=__doc__)
    sub=p.add_subparsers(dest='command',required=True)
    d=sub.add_parser('database'); d.add_argument('archive',type=Path); d.add_argument('--from-010',type=Path,required=True); d.add_argument('--from-020',type=Path,required=True); d.add_argument('--from-029',type=Path,required=True); d.add_argument('--from-030',type=Path,required=True); d.add_argument('--from-032',type=Path,required=True); d.add_argument('--from-033',type=Path,required=True); d.add_argument('--from-034',type=Path,required=True); d.add_argument('--from-035',type=Path,required=True); d.add_argument('--from-036',type=Path,required=True); d.add_argument('--from-037',type=Path,required=True); d.add_argument('--from-038',type=Path,required=True); d.add_argument('--from-039',type=Path,required=True); d.add_argument('--from-040',type=Path,required=True); d.add_argument('--from-041',type=Path,required=True); d.add_argument('--from-042',type=Path,required=True); d.add_argument('--from-043',type=Path,required=True); d.add_argument('--from-044',type=Path,required=True); d.add_argument('--from-045',type=Path,required=True); d.add_argument('--from-046',type=Path,required=True); d.add_argument('--from-047',type=Path,required=True); d.add_argument('--from-048',type=Path,required=True); d.add_argument('--from-049',type=Path,required=True); d.add_argument('--from-0410',type=Path,required=True); d.add_argument('--from-050',type=Path,required=True); d.add_argument('--from-051',type=Path,required=True); d.add_argument('--from-052',type=Path,required=True); d.add_argument('--from-053',type=Path,required=True); d.add_argument('--from-054',type=Path,required=True); d.add_argument('--from-055',type=Path,required=True); d.add_argument('--from-056',type=Path,required=True); d.add_argument('--from-057',type=Path,required=True); d.add_argument('--from-058',type=Path,required=True); d.add_argument('--from-059',type=Path,required=True); d.add_argument('--from-0510',type=Path,required=True); d.add_argument('--from-060',type=Path,required=True); d.add_argument('--from-061',type=Path,required=True); d.add_argument('--from-062',type=Path,required=True); d.add_argument('--from-063',type=Path,required=True); d.add_argument('--from-064',type=Path,required=True); d.add_argument('--from-065',type=Path,required=True); d.add_argument('--from-066',type=Path,required=True); d.add_argument('--from-067',type=Path,required=True); d.add_argument('--from-068',type=Path,required=True); d.add_argument('--from-069',type=Path,required=True); d.add_argument('--from-079',type=Path,required=True); d.add_argument('--from-080',type=Path,required=True); d.add_argument('--from-081',type=Path,required=True); d.add_argument('--from-082',type=Path,required=True); d.add_argument('--from-083',type=Path,required=True); d.add_argument('--from-084',type=Path,required=True); d.add_argument('--from-085',type=Path,required=True); d.add_argument('--output',type=Path,required=True)
    a=sub.add_parser('admin'); a.add_argument('archive',type=Path); a.add_argument('--output',type=Path,required=True)
    r=sub.add_parser('release'); r.add_argument('archive',type=Path); r.add_argument('--reports',type=Path,required=True); r.add_argument('--admin-report',type=Path,required=True); r.add_argument('--sdk-report',type=Path,required=True); r.add_argument('--analytics-storage-report',type=Path,required=True); r.add_argument('--integration-report',type=Path,required=True); r.add_argument('--supply-chain-report',type=Path,required=True); r.add_argument('--reproducibility-report',type=Path,required=True); r.add_argument('--output',type=Path,required=True)
    args=p.parse_args()
    report={'format':1,'scope':'database-core' if args.command=='database' else ('admin-panel' if args.command=='admin' else 'database-release-and-production-smoke'), 'status':'failed','stages':[], 'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
    try:
        report.update(archive_info(args.archive))
        {'database':database,'admin':admin_panel,'release':final_release}[args.command](args,report)
        report['status']='passed'
    except (Exception, KeyboardInterrupt) as e:
        # No captured environment, credentials, SQL or subprocess output in evidence.
        report['error_type']=type(e).__name__
        print(f'Certification failed: {type(e).__name__}',file=sys.stderr)
    finally:
        write_report(args.output,report)
    return 0 if report['status']=='passed' else 1


if __name__=='__main__': sys.exit(main())

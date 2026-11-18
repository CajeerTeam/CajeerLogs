#!/usr/bin/env python3
"""CajeerLogs 0.6.7 release gate, including live load/fault smoke. Executes real tests on a verified distribution.

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
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time

import release

VERSION = '0.6.7'
DATABASE_TESTS = (
    'database', 'migration_engine_database', 'transaction_database',
    'identity_database', 'identity_security_database', 'audit_database', 'rate_limit_database', 'rbac_database', 'ingest_database', 'delivery_database', 'hmac_database', 'redaction_database', 'search_database',
    'log_repository_database', 'webhook_database', 'webhook_subscriptions_database', 'queue_database', 'dead_letter_database','worker_microbatch_database', 'queue_backpressure_database','queue_fairness_database','queue_operations_database',
    'projects_database', 'sources_database', 'tokens_database',
    'integrity_database', 'security_database',
)
STAGES = tuple(DATABASE_TESTS) + ('pipeline', 'aapanel-integration', 'upgrade-0.1.0', 'upgrade-0.2.0', 'upgrade-0.2.9', 'upgrade-0.3.0', 'upgrade-0.3.2', 'upgrade-0.3.3', 'upgrade-0.3.4', 'upgrade-0.3.5', 'upgrade-0.3.6', 'upgrade-0.3.7', 'upgrade-0.3.8', 'upgrade-0.3.9', 'upgrade-0.4.0', 'upgrade-0.4.1', 'upgrade-0.4.2', 'upgrade-0.4.3','upgrade-0.4.4','upgrade-0.4.5', 'upgrade-0.4.6', 'upgrade-0.4.7','upgrade-0.4.8','upgrade-0.4.9','upgrade-0.4.10','upgrade-0.5.0','upgrade-0.5.1','upgrade-0.5.2','upgrade-0.5.3','upgrade-0.5.4','upgrade-0.5.5','upgrade-0.5.6','upgrade-0.5.7','upgrade-0.5.8','upgrade-0.5.9','upgrade-0.5.10','upgrade-0.6.0','upgrade-0.6.1','upgrade-0.6.2','upgrade-0.6.3','upgrade-0.6.4','upgrade-0.6.5','upgrade-0.6.6')
ADMIN_STAGES = ('release-runtime','http-runtime','health-monitoring','worker-runtime','worker-microbatch','throughput-planner','queue-backpressure','queue-fairness','queue-operations','retry-policy','monitoring-config','backup-operations', 'secure-installer', 'deployment-policy', 'deployment-tls', 'frontend-build', 'browser-ui-chromium', 'browser-db-chromium', 'browser-db-firefox', 'browser-db-webkit')
MATRIX = {(php, pg) for php in ('8.4', '8.5') for pg in ('17', '18')}


def archive_info(path):
    meta = release.verify(path)
    if meta['version'] != VERSION:
        raise ValueError('Expected the 0.6.7 release archive')
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
    start = time.monotonic()
    print('RUN', name, flush=True)
    stage = {'name': name, 'status': 'failed'}
    report['stages'].append(stage)
    try:
        result = subprocess.run(cmd, cwd=root, env=env, timeout=timeout, check=False)
        stage['exit_code'] = result.returncode
        if result.returncode != 0:
            raise RuntimeError(f'{name} failed (exit {result.returncode})')
        stage['status'] = 'passed'
    finally:
        stage['seconds'] = round(time.monotonic() - start, 3)


def database_environment():
    env = os.environ.copy()
    if env.get('CAJEER_TEST_DATABASE') != '1' or env.get('APP_ENV') != 'test':
        raise ValueError('Set CAJEER_TEST_DATABASE=1 and APP_ENV=test for a disposable database')
    required = ('DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD','CAJEER_TEST_RESTORE_DB','CAJEER_TEST_UPGRADE_RESTORE_DB')
    if any(not env.get(k) for k in required):
        raise ValueError('Explicit DB credentials and two restore databases are required')
    if len({env['DB_DATABASE'],env['CAJEER_TEST_RESTORE_DB'],env['CAJEER_TEST_UPGRADE_RESTORE_DB']}) != 3:
        raise ValueError('Use three distinct disposable databases')
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
    previous = {'0.1.0': args.from_010.resolve(), '0.2.0': args.from_020.resolve(), '0.2.9': args.from_029.resolve(), '0.3.0': args.from_030.resolve(), '0.3.2': args.from_032.resolve(), '0.3.3': args.from_033.resolve(), '0.3.4': args.from_034.resolve(), '0.3.5': args.from_035.resolve(), '0.3.6': args.from_036.resolve(), '0.3.7': args.from_037.resolve(), '0.3.8': args.from_038.resolve(), '0.3.9': args.from_039.resolve(), '0.4.0': args.from_040.resolve(), '0.4.1': args.from_041.resolve(), '0.4.2': args.from_042.resolve(), '0.4.3': args.from_043.resolve(), '0.4.4': args.from_044.resolve(), '0.4.5': args.from_045.resolve(), '0.4.6': args.from_046.resolve(), '0.4.7': args.from_047.resolve(),'0.4.8': args.from_048.resolve(),'0.4.9': args.from_049.resolve(),'0.4.10': args.from_0410.resolve(),'0.5.0': args.from_050.resolve(),'0.5.1': args.from_051.resolve(),'0.5.2': args.from_052.resolve(),'0.5.3': args.from_053.resolve(),'0.5.4': args.from_054.resolve(),'0.5.5': args.from_055.resolve(), '0.5.6': args.from_056.resolve(), '0.5.7': args.from_057.resolve(), '0.5.8': args.from_058.resolve(), '0.5.9': args.from_059.resolve(), '0.5.10': args.from_0510.resolve(), '0.6.0': args.from_060.resolve(), '0.6.1': args.from_061.resolve(), '0.6.2': args.from_062.resolve(), '0.6.3': args.from_063.resolve(), '0.6.4': args.from_064.resolve(), '0.6.5': args.from_065.resolve(), '0.6.6': args.from_066.resolve()}
    for version,path in previous.items():
        if json.loads((path/'release.json').read_text())['version'] != version:
            raise ValueError(f'Expected actual source tree {version}')
    with tempfile.TemporaryDirectory(prefix='cl-core-certify-') as tmp:
        root = Path(tmp)/'app'; release.verify(args.archive,root)
        report.update(probe_runtime(root,env))
        for name in DATABASE_TESTS:
            run_stage(report,name,['php',f'tests/{name}.php'],root,env)
        run_stage(report,'pipeline',[sys.executable,'tests/pipeline_database.py'],root,env)
        run_stage(report,'aapanel-integration',[sys.executable,'tests/aapanel_integration_stable.py'],root,env)
        for version,path in previous.items():
            run_stage(report,'upgrade-'+version,[sys.executable,'tests/upgrade_release.py',str(path)],root,env)


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
        if not str(r.get('php','')).startswith(pair[0]+'.') or not str(r.get('postgres','')).startswith(pair[1]+'.'):
            raise ValueError('Database runtime version mismatch')
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
    """No CI release badge without the real HTTPS+PostgreSQL async load evidence."""
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
    durations=(60,120,900,60)
    for phase,duration in zip(phases,durations):
        if (phase.get('status')!='passed' or phase.get('duration_target_seconds',0)<duration or
            phase.get('admission_seconds',0)<duration*.98 or
            phase.get('async_jobs',0)<1 or
            phase.get('durable_events',0)<1 or
            phase.get('durable_events')!=phase.get('verified_events') or
            phase.get('errors') is None or
            not isinstance(phase.get('gates'),dict) or
            not phase['gates'] or not all(v is True for v in phase['gates'].values())):
            raise ValueError('Live queue throughput SLO/integrity gate failed')
    fault=phases[-1].get('fault_injection',{})
    if not isinstance(fault,dict) or any(fault.get(k,0)<1 for k in
        ('pre503','pre429','lost_ack','delay','upstream_success')):
        raise ValueError('Mandatory live fault injection was not exercised')


def final_release(args,report):
    paths=sorted(args.reports.rglob('database-certification.json'))
    reports=[json.loads(p.read_text()) for p in paths]
    validate_matrix(reports,report)
    report['database_reports']=reports
    admin_report=json.loads(args.admin_report.read_text())
    validate_admin(admin_report,report)
    report['admin_report']=admin_report
    # CI also requires successful TLS/reconnect/pool, split-role, SDK and browser jobs.
    # The release report binds four core DB reports, admin browser evidence and production smoke.
    with tempfile.TemporaryDirectory(prefix='cl-core-production-') as tmp:
        root=Path(tmp)/'app'; release.verify(args.archive,root)
        # This stage operates on real isolated Docker/PG/TLS, then performs
        # all four long async workload phases. No fabricated or short smoke
        # report can certify a stable 0.6.7 release.
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
    d=sub.add_parser('database'); d.add_argument('archive',type=Path); d.add_argument('--from-010',type=Path,required=True); d.add_argument('--from-020',type=Path,required=True); d.add_argument('--from-029',type=Path,required=True); d.add_argument('--from-030',type=Path,required=True); d.add_argument('--from-032',type=Path,required=True); d.add_argument('--from-033',type=Path,required=True); d.add_argument('--from-034',type=Path,required=True); d.add_argument('--from-035',type=Path,required=True); d.add_argument('--from-036',type=Path,required=True); d.add_argument('--from-037',type=Path,required=True); d.add_argument('--from-038',type=Path,required=True); d.add_argument('--from-039',type=Path,required=True); d.add_argument('--from-040',type=Path,required=True); d.add_argument('--from-041',type=Path,required=True); d.add_argument('--from-042',type=Path,required=True); d.add_argument('--from-043',type=Path,required=True); d.add_argument('--from-044',type=Path,required=True); d.add_argument('--from-045',type=Path,required=True); d.add_argument('--from-046',type=Path,required=True); d.add_argument('--from-047',type=Path,required=True); d.add_argument('--from-048',type=Path,required=True); d.add_argument('--from-049',type=Path,required=True); d.add_argument('--from-0410',type=Path,required=True); d.add_argument('--from-050',type=Path,required=True); d.add_argument('--from-051',type=Path,required=True); d.add_argument('--from-052',type=Path,required=True); d.add_argument('--from-053',type=Path,required=True); d.add_argument('--from-054',type=Path,required=True); d.add_argument('--from-055',type=Path,required=True); d.add_argument('--from-056',type=Path,required=True); d.add_argument('--from-057',type=Path,required=True); d.add_argument('--from-058',type=Path,required=True); d.add_argument('--from-059',type=Path,required=True); d.add_argument('--from-0510',type=Path,required=True); d.add_argument('--from-060',type=Path,required=True); d.add_argument('--from-061',type=Path,required=True); d.add_argument('--from-062',type=Path,required=True); d.add_argument('--from-063',type=Path,required=True); d.add_argument('--from-064',type=Path,required=True); d.add_argument('--from-065',type=Path,required=True); d.add_argument('--from-066',type=Path,required=True); d.add_argument('--output',type=Path,required=True)
    a=sub.add_parser('admin'); a.add_argument('archive',type=Path); a.add_argument('--output',type=Path,required=True)
    r=sub.add_parser('release'); r.add_argument('archive',type=Path); r.add_argument('--reports',type=Path,required=True); r.add_argument('--admin-report',type=Path,required=True); r.add_argument('--output',type=Path,required=True)
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

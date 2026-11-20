#!/usr/bin/env python3
"""Destructive only against three explicitly provisioned empty CI PostgreSQL databases.

Exercises actual CLI pg_dump, authenticated offsite encryption, offsite-only
recovery after local disappearance, real pg_restore, data-loss boundary and
fail-closed faults. Evidence is bound to the verified 1.0.0 distribution.
"""
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

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import release

CHECKS=('pre_snapshot_write','post_snapshot_write','authenticated_replication',
        'missing_offsite_rejected','wrong_key_rejected','corruption_rejected',
        'timeout_rejected','live_database_rejected','stale_rpo_rejected',
        'local_archive_unavailable','offsite_only_restore','data_boundary_verified',
        'queue_integrity','audit_integrity','recovery_readiness','scratch_removed')


def run(command, env, *, check=True, timeout=120):
    p=subprocess.run(command,cwd=ROOT,env=env,text=True,capture_output=True,timeout=timeout)
    if check and p.returncode:
        # Never print stderr: it may contain connection strings or env values.
        label = command[2] if len(command)>2 and command[:2]==['php','bin/cajeer'] else Path(command[0]).name
        raise RuntimeError(f'Failed command {label} exit={p.returncode}')
    return p


def cli(env,*parts,fail=False):
    p=run(['php','bin/cajeer',*parts],env,check=not fail,timeout=150)
    if fail:
        if p.returncode==0: raise AssertionError('Unsafe operation unexpectedly succeeded')
        return None
    return json.loads(p.stdout)


def sql(env,statement,db=None):
    pe=dict(env,PGPASSWORD=env['DB_PASSWORD'],PGSSLMODE=env.get('DB_SSLMODE','disable'),
            PGCONNECT_TIMEOUT='5')
    command=['psql','-X','-q','-t','-A','-v','ON_ERROR_STOP=1','-h',env['DB_HOST'],
             '-p',env.get('DB_PORT','5432'),'-U',env['DB_USERNAME'],
             '-d',db or env['DB_DATABASE'],'-c',statement]
    return run(command,pe).stdout.strip()


def main():
    names=['CAJEER_TEST_DR_SOURCE_DB','CAJEER_TEST_DR_TARGET_DB','CAJEER_TEST_DR_REJECT_DB']
    if os.getenv('CAJEER_TEST_DR')!='1' or os.getenv('APP_ENV')!='test' or os.getenv('CAJEER_TEST_DATABASE')!='1':
        raise RuntimeError('DR test requires explicit disposable test opt-in')
    if any(not os.getenv(n,'').startswith('cajeer_dr_') for n in names):
        raise RuntimeError('Refusing databases outside cajeer_dr_* test namespace')
    if len(set(os.environ[n] for n in names+[ 'DB_DATABASE' ]))!=4:
        raise RuntimeError('DR databases and existing live test DB must be distinct')
    if any(not os.getenv(n) for n in ('DB_HOST','DB_USERNAME','DB_PASSWORD','PG_DUMP_BIN','PG_RESTORE_BIN')):
        raise RuntimeError('Required PostgreSQL client executables and credentials not configured')
    if any(not Path(os.environ[n]).is_file() for n in ('PG_DUMP_BIN','PG_RESTORE_BIN')):
        raise RuntimeError('PostgreSQL executable missing')
    archive=Path(os.environ['CAJEER_TEST_RELEASE_ARCHIVE']).resolve()
    info=release.verify(archive)
    if info['version']!='1.0.0' or json.loads((ROOT/'release.json').read_text())['version']!='1.0.0':
        raise RuntimeError('DR drill must run on the actual 1.0.0 archive')
    report={'format':1,'scope':'disaster-recovery','status':'failed','version':'1.0.0',
            'archive_sha256':hashlib.sha256(archive.read_bytes()).hexdigest(),
            'checks':[],'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
    output=Path(os.environ['CAJEER_TEST_DR_REPORT']).resolve()
    with tempfile.TemporaryDirectory(prefix='cajeer-dr-') as temp:
        base=Path(temp); local=base/'local'; remote=base/'offsite'; local.mkdir(mode=0o700); remote.mkdir(mode=0o700)
        env=dict(os.environ,DB_DATABASE=os.environ[names[0]],DB_SCHEMA='public',
                 BACKUP_DIR=str(local),BACKUP_OFFSITE_DIR=str(remote),
                 BACKUP_OFFSITE_REQUIRE_DISTINCT_DEVICE='false',BACKUP_MIN_FREE_BYTES='0',
                 BACKUP_ENCRYPTION_KEY=os.urandom(32).hex(),APP_DEBUG='false')
        env.pop('CAJEER_ENV_FILE',None); env.pop('DB_MIGRATION_USERNAME',None); env.pop('DB_MIGRATION_PASSWORD',None)
        source=env['DB_DATABASE']; target=os.environ[names[1]]; rejected=os.environ[names[2]]
        try:
            # The CI job owns three freshly provisioned databases. Refuse any leftovers.
            for db in (source,target,rejected):
                if sql(env,"SELECT EXISTS(SELECT 1 FROM pg_class WHERE relname='dr_proof')",db)!='f':
                    raise RuntimeError('DR fixture database already used')
            run(['php','bin/cajeer','migrate'],env)
            sql(env,'CREATE TABLE dr_proof (marker text PRIMARY KEY, written_at timestamptz NOT NULL DEFAULT clock_timestamp())')
            sql(env,"INSERT INTO dr_proof(marker) VALUES ('before_snapshot')")
            # Real logical data-consistency fixture, not merely a single marker.
            # The payloads are deterministic and bounded, so the restored table's
            # row count and content checksum can be checked independently.
            for i in range(32):
                sql(env, "INSERT INTO dr_proof(marker) VALUES ('proof-%03d-%s')" %
                    (i, hashlib.sha256(('snapshot:'+str(i)).encode()).hexdigest()[:24]))
            snapshot_rows=int(sql(env,'SELECT count(*) FROM dr_proof'))
            snapshot_checksum=sql(env,"SELECT md5(string_agg(marker, ',' ORDER BY marker)) FROM dr_proof")
            report['checks'].append('pre_snapshot_write')
            backup=cli(env,'backup:create')
            if backup['version']!='1.0.0' or not backup.get('snapshot_started_at') or backup['bytes']<1:
                raise AssertionError('Unusable recovery point')
            sql(env,"INSERT INTO dr_proof(marker) VALUES ('after_snapshot')")
            report['checks'].append('post_snapshot_write')
            copy=cli(env,'backup:replicate',backup['id'])
            if not copy['offsite_verified'] or copy['sha256']!=backup['sha256']:
                raise AssertionError('Encrypted offsite copy was not read back')
            report['checks'].append('authenticated_replication')
            args=('backup:dr-certify',backup['id'],rejected,'2592000','300')
            missing=dict(env,BACKUP_OFFSITE_DIR=str(base/'unmounted'))
            cli(missing,*args,fail=True); report['checks'].append('missing_offsite_rejected')
            wrong=dict(env,BACKUP_ENCRYPTION_KEY=os.urandom(32).hex())
            cli(wrong,*args,fail=True); report['checks'].append('wrong_key_rejected')
            offsite=remote/(backup['id']+'.cjr')
            with offsite.open('r+b') as f:
                f.seek(72); original=f.read(1)
                if not original: raise AssertionError('Empty encrypted package')
                f.seek(72); f.write(bytes([original[0]^0x40])); f.flush(); os.fsync(f.fileno())
            try: cli(env,*args,fail=True)
            finally:
                with offsite.open('r+b') as f:
                    f.seek(72);f.write(original);f.flush();os.fsync(f.fileno())
            report['checks'].append('corruption_rejected')
            sleeper=base/'sleep_restore'
            sleeper.write_text('#!/bin/sh\nexec sleep 10\n'); sleeper.chmod(0o700)
            slow=dict(env,PG_RESTORE_BIN=str(sleeper),BACKUP_TIMEOUT_SECONDS='1')
            began=time.monotonic(); cli(slow,*args,fail=True)
            if time.monotonic()-began>7: raise AssertionError('PG client timeout not enforced')
            report['checks'].append('timeout_rejected')
            # Never restore over the source database, even if a caller asks for it.
            cli(env,'backup:dr-certify',backup['id'],source,'2592000','300',fail=True)
            report['checks'].append('live_database_rejected')
            time.sleep(2)
            cli(env,'backup:dr-certify',backup['id'],rejected,'1','300',fail=True)
            report['checks'].append('stale_rpo_rejected')
            if sql(env,"SELECT EXISTS(SELECT 1 FROM pg_class WHERE relname='dr_proof')",rejected)!='f':
                raise AssertionError('Failed DR preflights mutated target')
            # The local archive is no longer available. The recovery path MUST
            # decrypt the offsite package into new scratch storage, not fall back.
            local_original=local/backup['id']
            local_original.rename(base/'destroyed_local_copy')
            if local_original.exists(): raise AssertionError('Local archive still visible')
            report['checks'].append('local_archive_unavailable')
            result=cli(env,'backup:dr-certify',backup['id'],target,'2592000','300')
            if (result['certification']!='passed' or result['recovery_path']!='authenticated_offsite_only'
                or result['offsite_sha256']!=backup['sha256'] or result['status']!='restored'
                or result['cutover_performed'] is not False or not result['integrity_verified']):
                raise AssertionError('Real offsite recovery not completed')
            report['checks'].append('offsite_only_restore')
            targetenv=dict(env,DB_DATABASE=target)
            markers=sql(env,"SELECT marker FROM dr_proof WHERE marker='before_snapshot'",target).splitlines()
            restored_rows=int(sql(env,'SELECT count(*) FROM dr_proof',target))
            restored_checksum=sql(env,"SELECT md5(string_agg(marker, ',' ORDER BY marker)) FROM dr_proof",target)
            if (markers!=['before_snapshot'] or restored_rows!=snapshot_rows or
                restored_checksum!=snapshot_checksum or
                sql(env,"SELECT count(*) FROM auth_sessions",target)!='0'):
                raise AssertionError('Snapshot boundary or credential invalidation failed')
            report['checks'].append('data_boundary_verified')
            if not cli(targetenv,'queue:verify')['valid']:
                raise AssertionError('Restored queue accounting invalid')
            report['checks'].append('queue_integrity')
            if not cli(targetenv,'audit:verify')['valid']:
                raise AssertionError('Restored audit chain invalid')
            report['checks'].append('audit_integrity')
            ready=cli(targetenv,'db:verify')
            if ready['status']!='ok': raise AssertionError('Restored target failed database readiness')
            if not (0<=result['rpo_upper_bound_seconds']<=2592000 and
                    0<result['recovery_readiness_seconds']<=300):
                raise AssertionError('DR objectives not met')
            report['checks'].append('recovery_readiness')
            if list(local.glob('.dr-*')) or local_original.exists():
                raise AssertionError('Plaintext scratch or missing archive was silently republished')
            report['checks'].append('scratch_removed')
            report.update(status='passed',backup_sha256=backup['sha256'],
                          rpo_upper_bound_seconds=result['rpo_upper_bound_seconds'],
                          recovery_readiness_seconds=result['recovery_readiness_seconds'],
                          source_version=backup['version'],
                          snapshot_rows=snapshot_rows,restored_rows=restored_rows,
                          snapshot_checksum=snapshot_checksum,
                          restored_checksum=restored_checksum,
                          fault_domain_separation_proven=False,
                          postgres_major=sql(env,'SHOW server_version_num',target)[:2])
        finally:
            if report['status']=='failed':
                print('DR last completed check: '+(report['checks'][-1] if report['checks'] else 'none'),file=sys.stderr)
            # Preserve failure in the report; CI owns database lifecycle.
            output.parent.mkdir(parents=True,exist_ok=True)
            output.write_text(json.dumps(report,indent=2,sort_keys=True)+'\n')
    if report['checks']!=list(CHECKS): raise AssertionError('DR scenario evidence incomplete')
    print('PASS actual offsite-only disaster recovery: '+', '.join(report['checks']),flush=True)


if __name__=='__main__':
    try: main()
    except Exception as e:
        reason=str(e) if isinstance(e,RuntimeError) and str(e).startswith('Failed command ') else ''
        print('DR certification failed: '+type(e).__name__+(': '+reason if reason else ''),file=sys.stderr)
        sys.exit(1)

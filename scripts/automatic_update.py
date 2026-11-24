#!/usr/bin/env python3
"""Operator-owned scheduled GitHub updates; never execute commands from web input."""
import argparse
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import sys
import time
from datetime import datetime, timezone
from safe_update import Installer, Host, UpdateError, atomic_json, private_dir, check_regular, DB, UNIT, VERSION

ROOT = Path(__file__).resolve().parent.parent
MODES = ('off', 'check', 'download', 'install')
CHANNELS = ('stable', 'preview')
SEMVER = re.compile(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?')
AUTO_UNITS = {'cajeerlogs-auto-update.service', 'cajeerlogs-auto-update.timer'}
DEFAULT = {'mode': 'off', 'channel': 'stable', 'interval_minutes': 60, 'window_start': '03:00',
           'window_minutes': 60, 'units': [], 'databases': [], 'sudo_systemctl': False}


def policy(value):
    if not isinstance(value, dict) or set(value) != set(DEFAULT):
        raise UpdateError('Invalid automatic update policy.')
    if value['mode'] not in MODES or value['channel'] not in CHANNELS:
        raise UpdateError('Invalid automatic update mode or channel.')
    if type(value['interval_minutes']) is not int or not 5 <= value['interval_minutes'] <= 10080:
        raise UpdateError('Check interval must be 5–10080 minutes.')
    if not isinstance(value['window_start'], str) or not re.fullmatch(r'(?:[01][0-9]|2[0-3]):[0-5][0-9]', value['window_start']):
        raise UpdateError('Maintenance window must be HH:MM UTC.')
    if type(value['window_minutes']) is not int or not 5 <= value['window_minutes'] <= 240:
        raise UpdateError('Maintenance window must be 5–240 minutes.')
    for field, matcher, maximum in [('units', UNIT, 64), ('databases', DB, 32)]:
        items = value[field]
        if not isinstance(items, list) or len(items) > maximum or not all(isinstance(x, str) and matcher.fullmatch(x) for x in items) or len(set(items)) != len(items):
            raise UpdateError('Invalid maintenance inventory.')
    if set(value['units']) & AUTO_UNITS or set(value['databases']) & {'postgres','template0','template1'} or type(value['sudo_systemctl']) is not bool:
        raise UpdateError('Unsafe maintenance inventory.')
    if value['mode'] == 'install' and (value['channel'] != 'stable' or not value['units'] or not value['databases']):
        raise UpdateError('Automatic installation requires stable channel, complete unit inventory and pre-provisioned empty databases.')
    return value


def in_window(p, now):
    dt = datetime.fromtimestamp(now, timezone.utc)
    h, m = map(int, p['window_start'].split(':'))
    return (dt.hour * 60 + dt.minute - h * 60 - m) % 1440 < p['window_minutes']


def read_private(path, default):
    if not path.exists() and not path.is_symlink():
        return default
    st = check_regular(path)
    if st.st_mode & 0o077 or st.st_size > 65536:
        raise UpdateError('Unsafe automatic update state.')
    obj = json.loads(path.read_text(encoding='utf-8'))
    if not isinstance(obj, dict):
        raise UpdateError('Invalid automatic update state.')
    return obj


class Manager:
    def __init__(self, root, host=None, installer_factory=Installer, clock=time.time):
        self.root = root
        self.directory = root.parent / ('.' + root.name + '.automatic-update')
        self.policy_path = self.directory / 'policy.json'
        self.state_path = self.directory / 'state.json'
        self.host = host
        self.installer_factory = installer_factory
        self.clock = clock

    def lock(self):
        parent = self.root.parent.lstat()
        if self.root.resolve() != self.root or self.root.lstat().st_uid != os.geteuid() or parent.st_uid != os.geteuid() or parent.st_mode & 0o022 or os.geteuid() == 0:
            raise UpdateError('Run automatic updates as the non-root owner of a canonical, protected native installation.')
        private_dir(self.directory, True)
        fd = os.open(self.directory / 'lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_uid != os.geteuid() or st.st_nlink != 1 or st.st_mode & 0o077:
            os.close(fd); raise UpdateError('Unsafe automation lock.')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            os.close(fd); return None
        return fd

    def settings(self):
        return policy(read_private(self.policy_path, DEFAULT.copy()))

    def state(self):
        return read_private(self.state_path, {'last_check': 0, 'candidate': None, 'downloaded': None, 'status': 'idle',
            'blocked': False, 'used_databases': [], 'notices': [], 'updated_at': 0})

    def notify(self, s, kind, version=None):
        entry = {'kind': kind, 'version': version, 'at': int(self.clock())}
        if not s['notices'] or any(s['notices'][-1].get(k) != entry[k] for k in ('kind','version')):
            s['notices'] = (s['notices'] + [entry])[-20:]

    def summary(self, p, s):
        return {'mode': p['mode'], 'channel': p['channel'], 'interval_minutes': p['interval_minutes'],
                'window_start': p['window_start'], 'window_minutes': p['window_minutes'],
                'status': s['status'], 'blocked': s['blocked'], 'candidate': s['candidate'],
                'downloaded': s['downloaded'], 'last_check': s['last_check'],
                'next_check': s['last_check'] + p['interval_minutes'] * 60 if s['last_check'] and p['mode'] != 'off' else None,
                'updated_at': s['updated_at'], 'notices': s['notices'],
                'remaining_databases': len(set(p['databases']) - set(s['used_databases']))}

    def save(self, p, s):
        s['updated_at'] = int(self.clock())
        atomic_json(self.state_path, s)
        # Projection is display-only. Never trust it for policy or execution.
        cache = self.root / 'storage/cache'
        if cache.is_dir() and cache.resolve() == cache and cache.stat().st_uid == os.geteuid():
            try: atomic_json(cache / 'update-automation.json', self.summary(p, s))
            except OSError: pass  # Durable operator state remains authoritative.
        return self.summary(p, s)

    def configure(self, p):
        p = policy(p)
        s = self.state()
        atomic_json(self.policy_path, p)
        s.update(last_check=0, candidate=None, downloaded=None)
        if not s['blocked'] and s['status'] != 'installing': s['status'] = 'off' if p['mode'] == 'off' else 'idle'
        return self.save(p, s)

    def resume(self):
        p, s = self.settings(), self.state()
        installer = self.installer_factory(self.root, self.host or Host(p['sudo_systemctl']))
        fd = installer.lock()
        try:
            previous = installer.read()
            if previous and previous['phase'] not in ('complete','rolled_back'):
                raise UpdateError('Recover the unfinished safe update before resuming automation.')
        finally: os.close(fd)
        s.update(blocked=False, status='idle', last_check=0, candidate=None, downloaded=None)
        self.notify(s, 'resumed')
        return self.save(p, s)

    def discover(self, p, s, host):
        s['last_check'] = int(self.clock())
        data = host.cli(self.root, 'update:check', '--refresh', '--channel='+p['channel'], timeout=60)
        if data.get('status') not in ('ok','no_releases') or data.get('channel') != p['channel'] or type(data.get('update_available')) is not bool:
            raise UpdateError('Release discovery failed.')
        candidate = data.get('candidate') if data['update_available'] else None
        if candidate is not None and (not isinstance(candidate,str) or len(candidate)>128 or not SEMVER.fullmatch(candidate)):
            raise UpdateError('Invalid candidate version.')
        if candidate and p['channel']=='stable' and not VERSION.fullmatch(candidate):
            raise UpdateError('Stable policy rejected a preview candidate.')
        if candidate != s['candidate']:
            s['downloaded'] = None
            if candidate: self.notify(s, 'available', candidate)
        s['candidate'] = candidate
        s['status'] = 'available' if candidate else 'up_to_date'

    def tick(self):
        p, s = self.settings(), self.state()
        host = self.host or Host(p['sudo_systemctl'])
        now = int(self.clock())
        if s['status'] == 'installing':
            s.update(blocked=True,status='interrupted'); self.notify(s,'interrupted',s['candidate'])
        if p['mode']=='off':
            if not s['blocked']: s['status']='off'
            return self.save(p,s)
        if s['blocked']: return self.save(p,s)
        due = not s['last_check'] or now < s['last_check'] or now-s['last_check'] >= p['interval_minutes']*60
        installing = p['mode']=='install' and s['candidate'] and s['status'] not in ('check_or_download_failed','installer_unavailable') and in_window(p,now)
        if not due and not installing: return self.save(p,s)
        try:
            # Always refresh immediately before an installation window; a stale candidate is never installed.
            self.discover(p,s,host)
            candidate=s['candidate']
            if candidate and p['mode'] in ('download','install') and s['downloaded']!=candidate:
                proof=host.cli(self.root,'update:download','--channel='+p['channel'],'--version='+candidate,timeout=1800)
                if proof.get('status')!='verified' or proof.get('version')!=candidate or proof.get('provenance')!='github_oidc_sigstore' or not re.fullmatch(r'[a-f0-9]{64}',str(proof.get('sha256',''))):
                    raise UpdateError('Automatic download did not return verified provenance.')
                s['downloaded']=candidate; self.notify(s,'downloaded',candidate)
            if candidate and p['mode'] in ('download','install'): s['status']='downloaded'
        except (Exception, KeyboardInterrupt):
            s['status']='check_or_download_failed'; self.notify(s,'check_or_download_failed',s['candidate'])
            return self.save(p,s)
        if not (candidate and p['mode']=='install' and in_window(p,self.clock())):
            return self.save(p,s)
        databases=[d for d in p['databases'] if d not in s['used_databases']]
        if not databases:
            s.update(blocked=True,status='database_pool_exhausted'); self.notify(s,'database_pool_exhausted',candidate)
            return self.save(p,s)
        # No automatic installation with external overrides or an unfinished manual update.
        try:
            if any(k.startswith('DB_') or k in ('CAJEER_ENV_FILE','CAJEER_RELEASE_VERSION','APP_URL','BACKUP_DIR') for k in os.environ):
                raise UpdateError('Remove external database/runtime overrides.')
            installer=self.installer_factory(self.root,host)
            fd=installer.lock()
        except (Exception, KeyboardInterrupt):
            s['status']='installer_unavailable'; self.notify(s,'installer_unavailable',candidate)
            return self.save(p,s)
        try:
            try: previous=installer.read()
            except Exception:
                s.update(blocked=True,status='recovery_required'); self.notify(s,'recovery_required',candidate)
                return self.save(p,s)
            if previous and previous['phase'] not in ('complete','rolled_back'):
                s.update(blocked=True,status='recovery_required'); self.notify(s,'recovery_required',candidate)
                return self.save(p,s)
            if not in_window(p,self.clock()): return self.save(p,s)
            # Reserve a database permanently before starting. Never reuse an attempted clone automatically.
            db=databases[0]; s['used_databases'].append(db); s['status']='installing'
            self.notify(s,'installing',candidate); self.save(p,s)
            try:
                result=installer.install(candidate,db,p['units'])
                if result.get('phase')!='complete' or result.get('to')!=candidate:
                    raise UpdateError('Installation did not complete.')
                s.update(status='installed',candidate=None,downloaded=None,last_check=int(self.clock()))
                self.notify(s,'installed',candidate)
            except (Exception, KeyboardInterrupt):
                s.update(blocked=True,status='installation_failed'); self.notify(s,'installation_failed',candidate)
            return self.save(p,s)
        finally: os.close(fd)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    sub=parser.add_subparsers(dest='action',required=True)
    c=sub.add_parser('configure'); c.add_argument('--mode',choices=MODES,required=True); c.add_argument('--channel',choices=CHANNELS,default='stable')
    c.add_argument('--interval-minutes',type=int,default=60); c.add_argument('--window-start',default='03:00'); c.add_argument('--window-minutes',type=int,default=60)
    c.add_argument('--unit',action='append',default=[]); c.add_argument('--target-db',action='append',default=[]); c.add_argument('--sudo-systemctl',action='store_true'); c.add_argument('--confirm',action='store_true')
    sub.add_parser('tick'); sub.add_parser('status'); r=sub.add_parser('resume'); r.add_argument('--confirm',action='store_true',required=True)
    args=parser.parse_args(); os.umask(0o077)
    try:
        if sys.platform!='linux': raise UpdateError('Automatic update management requires Linux.')
        manager=Manager(ROOT); fd=manager.lock()
        if fd is None:
            print(json.dumps({'status':'busy'})); return 0 if args.action=='tick' else 1
        try:
            if args.action=='configure':
                if args.mode=='install' and not args.confirm: raise UpdateError('Unattended installation requires explicit --confirm.')
                result=manager.configure({'mode':args.mode,'channel':args.channel,'interval_minutes':args.interval_minutes,'window_start':args.window_start,'window_minutes':args.window_minutes,'units':args.unit,'databases':args.target_db,'sudo_systemctl':args.sudo_systemctl})
            elif args.action=='tick': result=manager.tick()
            elif args.action=='resume': result=manager.resume()
            else: result=manager.summary(manager.settings(),manager.state())
            print(json.dumps(result,indent=2,sort_keys=True))
            return int(args.action=='tick' and (result.get('blocked') or result.get('status') in ('check_or_download_failed','installer_unavailable')))
        finally: os.close(fd)
    except (Exception, KeyboardInterrupt) as error:
        print('Automatic update management failed: '+(str(error) if isinstance(error,UpdateError) else type(error).__name__),file=sys.stderr); return 1

if __name__=='__main__': sys.exit(main())

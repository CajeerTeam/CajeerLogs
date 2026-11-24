#!/usr/bin/env python3
"""Operator-only, crash-recoverable native Linux update with isolated PostgreSQL cutover."""
import argparse
import ctypes
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import ssl
import stat
import subprocess
import sys
import tempfile
import time
import urllib.request
import urllib.parse
import uuid
import release

ROOT = Path(__file__).resolve().parent.parent
VERSION = re.compile(r'^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$')
UNIT = re.compile(r'^[A-Za-z0-9][A-Za-z0-9_.@-]{0,127}\.(?:service|timer|socket|path)$')
DB = re.compile(r'^[a-z_][a-z0-9_]{0,62}$')

class UpdateError(RuntimeError):
    pass


def syncdir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def atomic_json(path, data):
    fd, name = tempfile.mkstemp(prefix='.journal-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as out:
            json.dump(data, out, sort_keys=True, indent=2)
            out.write('\n')
            out.flush(); os.fsync(out.fileno())
        os.replace(name, path)
        syncdir(path.parent)
    finally:
        Path(name).unlink(missing_ok=True)


def private_dir(path, create=False):
    if create:
        path.mkdir(mode=0o700, exist_ok=True)
    st = path.lstat()
    if not stat.S_ISDIR(st.st_mode) or st.st_uid != os.geteuid() or st.st_mode & 0o077:
        raise UpdateError('Update state directory must be private (0700), owned and not linked.')


def inode(path):
    s = path.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid != os.geteuid():
        raise UpdateError('Unsafe release directory.')
    return [s.st_dev, s.st_ino]


def exchange(a, b):
    # Linux renameat2(RENAME_EXCHANGE): neither live name is ever absent.
    libc = ctypes.CDLL(None, use_errno=True)
    fn = getattr(libc, 'renameat2', None)
    if fn is None:
        raise UpdateError('Atomic directory exchange is unavailable.')
    fn.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    fn.restype = ctypes.c_int
    if fn(-100, os.fsencode(a), -100, os.fsencode(b), 2) != 0:
        err = ctypes.get_errno()
        raise OSError(err, os.strerror(err))
    syncdir(a.parent); syncdir(b.parent)


def digest(path):
    with path.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def version_key(value):
    if not isinstance(value, str) or len(value)>128 or not VERSION.fullmatch(value):
        raise UpdateError('Native installation accepts stable release versions only.')
    return tuple(map(int, value.split('.')))


def check_regular(path):
    st = path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or st.st_uid != os.geteuid():
        raise UpdateError('Runtime files must be owned regular files, not links.')
    return st


def copy_runtime(src, dst):
    # Do not follow links, special files, mounted directories or shared hardlinks.
    rootdev = src.lstat().st_dev
    def walk(a, b):
        st = a.lstat()
        if st.st_dev != rootdev or st.st_uid != os.geteuid() or not stat.S_ISDIR(st.st_mode):
            raise UpdateError('Runtime storage contains a linked, foreign-owned or mounted directory.')
        b.mkdir(mode=0o700, exist_ok=True)
        for child in a.iterdir():
            target = b / child.name
            mode = child.lstat().st_mode
            if stat.S_ISDIR(mode):
                walk(child, target)
            else:
                s = check_regular(child)
                if s.st_dev != rootdev:
                    raise UpdateError('Runtime storage contains a mounted file.')
                shutil.copyfile(child, target, follow_symlinks=False)
                os.chmod(target, s.st_mode & 0o777)
        os.chmod(b, st.st_mode & 0o777)
    walk(src, dst)


def sync_tree(root):
    for parent, dirs, files in os.walk(root, topdown=False, followlinks=False):
        for name in files:
            p = Path(parent) / name
            # Composer can create symlinks in vendor/bin; never follow them.
            if p.is_symlink():
                if not p.resolve().is_relative_to(root):
                    raise UpdateError('Staged dependency symlink escapes release.')
                continue
            fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
            try: os.fsync(fd)
            finally: os.close(fd)
        syncdir(parent)


def change_database(path, old, target):
    st = check_regular(path)
    if st.st_mode & 0o077 or not DB.fullmatch(old) or not DB.fullmatch(target) or old == target:
        raise UpdateError('Unsafe .env or database name.')
    body = path.read_text(encoding='utf-8')
    pattern = re.compile(r'(?m)^(?:[ \t]*export[ \t]+)?[ \t]*DB_DATABASE[ \t]*=.*$')
    body, count = pattern.subn('DB_DATABASE="'+target+'"', body)
    if count != 1:
        raise UpdateError('Exactly one explicit DB_DATABASE assignment is required.')
    with path.open('w', encoding='utf-8') as f:
        f.write(body); f.flush(); os.fsync(f.fileno())
    path.chmod(0o600)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Host:
    def __init__(self, sudo=False):
        self.systemctl = (['/usr/bin/sudo', '-n'] if sudo else []) + ['/usr/bin/systemctl']

    def run(self, argv, cwd=None, timeout=3600, check=True):
        # Never print command output: it can contain credentials or configuration.
        result = subprocess.run(argv, cwd=cwd, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=timeout, check=False)
        if check and result.returncode:
            raise UpdateError(f'Command failed ({Path(argv[0]).name}, exit {result.returncode}). Inspect service/database logs locally.')
        return result

    def cli(self, root, *args, helper=False, timeout=3600):
        script = 'scripts/update_database.php' if helper else 'bin/cajeer'
        r = self.run(['php', str(root / script), *args], cwd=root, timeout=timeout)
        try:
            value = json.loads(r.stdout)
        except (ValueError, UnicodeError) as e:
            raise UpdateError('Unexpected maintenance CLI response.') from e
        if not isinstance(value, dict):
            raise UpdateError('Invalid maintenance CLI response.')
        return value

    def prop(self, unit, name):
        return self.run(self.systemctl+['show', '--value', '--property='+name, unit], timeout=30).stdout.decode().strip()

    def units(self, names):
        active=[]
        for unit in names:
            if not UNIT.fullmatch(unit) or self.prop(unit,'LoadState') != 'loaded':
                raise UpdateError('Unknown, masked or invalid service unit.')
            # Persistent systemd masks must be able to occupy /etc/systemd/system.
            frag=self.prop(unit,'FragmentPath')
            if not (frag.startswith('/usr/lib/systemd/system/') or frag.startswith('/lib/systemd/system/')):
                raise UpdateError('Managed units must be installed under /usr/lib/systemd/system (persistent masking required).')
            if unit.endswith('.service'):
                if self.prop(unit,'EnvironmentFiles') or re.search(r'(?:^|\s)(?:DB_[A-Z_]+|CAJEER_ENV_FILE|CAJEER_RELEASE_VERSION|BACKUP_DIR|APP_URL)=',self.prop(unit,'Environment')):
                    raise UpdateError('Managed services must use the local .env, without database/version overrides.')
            state=self.prop(unit,'ActiveState')
            if state not in ('active','inactive'):
                raise UpdateError('Unit must be active or inactive before installation.')
            if state=='active': active.append(unit)
        if not active or not any(u.endswith('.service') for u in active):
            raise UpdateError('At least one active service is required.')
        return active

    def stop(self, units):
        # Mask first so timers, restarts, dependencies and host reboot cannot resume writes.
        self.run(self.systemctl+['mask','--now',*units], timeout=900)
        for unit in units:
            if self.prop(unit,'LoadState')!='masked' or self.prop(unit,'ActiveState') not in ('inactive','failed'):
                raise UpdateError('Not all managed units are persistently masked and stopped.')
            if unit.endswith('.service') and self.prop(unit,'MainPID') not in ('','0'):
                raise UpdateError('A managed service still has a running process.')

    def start(self, units, active):
        self.run(self.systemctl+['unmask',*units], timeout=60)
        # Re-arm only the units that were active before maintenance.
        if active:
            self.run(self.systemctl+['start',*active], timeout=900)

    def assert_services(self, active):
        for unit in active:
            state=self.prop(unit,'ActiveState')
            if unit.endswith('.service') and self.prop(unit,'Type')=='oneshot':
                if state not in ('active','inactive') or self.prop(unit,'Result')!='success':
                    raise UpdateError('A previously active oneshot service did not recover successfully.')
            elif state!='active':
                raise UpdateError('A previously active service or activator is not healthy.')

    def ready(self, root):
        value=self.cli(root,'health:ready',timeout=30)
        if value.get('status')!='ok':
            raise UpdateError('Local readiness check failed.')

    def http(self, url, version):
        parsed=urllib.parse.urlsplit(url)
        if parsed.scheme!='https' or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise UpdateError('Configured health endpoint must use HTTPS.')
        opener=urllib.request.build_opener(NoRedirect(),urllib.request.ProxyHandler({}),urllib.request.HTTPSHandler(context=ssl.create_default_context()))
        deadline=time.monotonic()+60
        while True:
            try:
                with opener.open(url.rstrip('/')+'/ready', timeout=5) as r:
                    body=json.loads(r.read(65537))
                    if r.status==200 and body.get('status')=='ok' and body.get('version')==version:
                        return
            except (OSError, ValueError, AttributeError):
                pass
            if time.monotonic()>=deadline:
                raise UpdateError('HTTPS readiness/version check failed.')
            time.sleep(1)

    def prepare(self, root, stage, target):
        proof=self.cli(root,'update:verify',target,timeout=600)
        if proof.get('status')!='verified' or proof.get('version')!=target or proof.get('provenance')!='github_oidc_sigstore' or not re.fullmatch(r'[a-f0-9]{64}',str(proof.get('sha256',''))):
            raise UpdateError('Downloaded release did not pass live provenance verification.')
        archive=root/'storage/archives/verified-updates'/('v'+target)/('CajeerLogs-'+target+'.zip')
        check_regular(archive)
        private=stage.parent/'candidate.zip'
        with archive.open('rb') as src, private.open('xb') as out:
            shutil.copyfileobj(src,out,1024*1024); out.flush(); os.fsync(out.fileno())
        if digest(private)!=proof['sha256']:
            raise UpdateError('Verified archive changed before extraction.')
        meta=release.verify(private,stage)
        # Extraction under umask 077 creates private directories; the public
        # document root still needs traversal by a separate static-file server.
        for path, dirs, files in os.walk(stage/'public'):
            os.chmod(path,0o755)
        if meta.get('version')!=target:
            raise UpdateError('Archive version mismatch.')
        # Do not reuse the old vendor tree; resolve only the signed locked dependencies.
        if not (stage/'composer.lock').is_file():
            raise UpdateError('Signed release is missing composer.lock.')
        self.run(['composer','install','--no-dev','--no-interaction','--no-plugins','--no-scripts','--prefer-dist','--classmap-authoritative'],cwd=stage,timeout=600)
        return proof['sha256']


class Installer:
    def __init__(self, root, host):
        self.root=root
        self.host=host
        self.state=root.parent/('.'+root.name+'.safe-update')
        self.journal=self.state/'state.json'

    def lock(self):
        p=self.root.parent.lstat()
        if self.root.is_symlink() or self.root.resolve()!=self.root or p.st_uid!=os.geteuid() or p.st_mode&0o022:
            raise UpdateError('Native release and its parent must be canonical and operator-owned, without group/world writes.')
        if os.geteuid()==0:
            raise UpdateError('Run as the application owner, not root.')
        inode(self.root)
        private_dir(self.state,True)
        fd=os.open(self.state/'lock',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
        s=os.fstat(fd)
        if not stat.S_ISREG(s.st_mode) or s.st_nlink!=1 or s.st_uid!=os.geteuid() or s.st_mode&0o077:
            os.close(fd); raise UpdateError('Unsafe updater lock.')
        try: fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: os.close(fd); raise UpdateError('Another update is running.') from None
        return fd

    def read(self):
        if not self.journal.exists(): return None
        check_regular(self.journal)
        if self.journal.stat().st_size>65536 or self.journal.stat().st_mode&0o077:
            raise UpdateError('Unsafe update journal.')
        data=json.loads(self.journal.read_text())
        if not isinstance(data,dict) or data.get('format')!=1 or not re.fullmatch(r'[a-f0-9]{32}',data.get('id','')):
            raise UpdateError('Invalid update journal.')
        if data.get('phase') not in ('prepared','stopped','cloned','switching','committed','complete','rolled_back','recovery_required') or not isinstance(data.get('units'),list) or not all(isinstance(u,str) and UNIT.fullmatch(u) for u in data['units']):
            raise UpdateError('Invalid update state.')
        if not isinstance(data.get('active'),list) or not set(data['active'])<=set(data['units']):
            raise UpdateError('Invalid service journal.')
        return data

    def save(self, data, phase=None):
        if phase: data['phase']=phase
        atomic_json(self.journal,data)

    def stage(self,data):
        return self.state/data['id']/'release'

    def rollback(self,data):
        # A durable commit is a point of no return: writers may already have accepted data.
        if data.get('committed'):
            raise UpdateError('Committed update cannot revert to the old database; recover the new deployment.')
        self.host.stop(data['units'])
        stage=self.stage(data)
        live=inode(self.root)
        if live==data.get('new_inode'):
            if inode(stage)!=data['old_inode']:
                raise UpdateError('Previous release identity changed; refusing rollback.')
            exchange(self.root,stage)
        elif live!=data['old_inode']:
            raise UpdateError('Live release identity changed; refusing rollback.')
        self.host.ready(self.root)
        self.host.start(data['units'],data['active'])
        self.host.http(data['url'],data['from'])
        self.host.assert_services(data['active'])
        self.save(data,'rolled_back')

    def finish(self,data):
        if not data.get('committed') or inode(self.root)!=data.get('new_inode'):
            raise UpdateError('Committed release identity does not match journal.')
        self.host.ready(self.root)
        self.host.start(data['units'],data['active'])
        self.host.http(data['url'],data['to'])
        self.host.assert_services(data['active'])
        self.save(data,'complete')

    def recover(self):
        data=self.read()
        if not data: raise UpdateError('No update journal exists.')
        if data['phase'] in ('complete','rolled_back'): return data
        try:
            if data.get('committed'): self.finish(data)
            else: self.rollback(data)
        except BaseException:
            # If readiness/activation fails, leave all writers stopped for operator repair.
            try: self.host.stop(data['units'])
            finally: self.save(data,'recovery_required')
            raise
        return data

    def install(self,target,db,units):
        previous=self.read()
        if previous and previous['phase'] not in ('complete','rolled_back'):
            raise UpdateError('An unfinished update exists. Run update:recover first.')
        if not DB.fullmatch(db) or db in ('postgres','template0','template1'):
            raise UpdateError('Invalid isolated database name.')
        if not units or len(set(units))!=len(units) or not all(UNIT.fullmatch(u) for u in units):
            raise UpdateError('Supply every writer/worker/ingress/timer unit exactly once.')
        current=self.host.cli(self.root,'inspect',helper=True,timeout=30)
        if version_key(target)<=version_key(current['version']) or db==current['database']:
            raise UpdateError('Target must be newer and use a different, empty database.')
        self.host.ready(self.root)
        self.host.http(current['url'],current['version'])
        active=self.host.units(units)
        data={'format':1,'id':uuid.uuid4().hex,'phase':'prepared','from':current['version'],'to':target,
              'source_database':current['database'],'target_database':db,'url':current['url'],'units':units,
              'active':active,'old_inode':inode(self.root),'committed':False}
        stage=self.stage(data); stage.parent.mkdir(mode=0o700);stage.mkdir(mode=0o700)
        # Preparation is read-only to production. A failed download/install never stops service.
        data['sha256']=self.host.prepare(self.root,stage,target)
        data['new_inode']=inode(stage)
        self.save(data,'prepared')
        try:
            self.host.stop(units);self.save(data,'stopped')
            if self.host.cli(self.root,'inspect',helper=True,timeout=30)!=current:
                raise UpdateError('Source configuration changed since preflight.')
            backup=self.host.cli(self.root,'snapshot','--confirm-stopped',helper=True,timeout=86400)
            if backup.get('version')!=data['from'] or not re.fullmatch(r'backup-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{16}',str(backup.get('id',''))) or not re.fullmatch(r'[a-f0-9]{64}',str(backup.get('sha256',''))):
                raise UpdateError('Backup was not verified for the running version.')
            data['backup_id']=backup['id'];data['backup_sha256']=backup['sha256'];self.save(data)
            cloned=self.host.cli(self.root,'clone',backup['id'],db,'--confirm-stopped',helper=True,timeout=86400)
            if cloned.get('status')!='cloned' or cloned.get('database')!=db or cloned.get('integrity_verified') is not True:
                raise UpdateError('Isolated database clone failed validation.')
            self.save(data,'cloned')
            check_regular(self.root/'.env')
            shutil.copyfile(self.root/'.env',stage/'.env');(stage/'.env').chmod(0o600)
            change_database(stage/'.env',current['database'],db)
            copy_runtime(self.root/'storage',stage/'storage')
            upgrade=self.host.cli(stage,'upgrade',backup['id'],'--confirm-stopped',timeout=86400)
            if upgrade.get('status')!='ready' or upgrade.get('version')!=target or upgrade.get('backup_id')!=backup['id'] or upgrade.get('schema_verified') is not True:
                raise UpdateError('Migrations or upgrade validation did not complete.')
            self.host.ready(stage)
            # Recheck the original database for external writers immediately before cutover.
            self.host.cli(self.root,'quiescent',helper=True,timeout=30)
            os.chmod(stage,self.root.stat().st_mode&0o777)
            sync_tree(stage);syncdir(stage.parent)
            self.save(data,'switching')
            exchange(self.root,stage)
            self.host.ready(self.root)
            # Persist commit before unmasking any writer. After this, never roll back data.
            data['committed']=True;self.save(data,'committed')
            self.finish(data)
            return data
        except BaseException:
            # Read durable state, not the in-memory flag: a failed journal write is ambiguous.
            durable=self.read()
            if durable is None: raise
            try:
                if durable.get('committed'):
                    self.host.stop(durable['units']);self.save(durable,'recovery_required')
                else: self.rollback(durable)
            except BaseException:
                try: self.host.stop(durable['units'])
                finally: self.save(durable,'recovery_required')
            raise


def main():
    p=argparse.ArgumentParser(description=__doc__)
    sub=p.add_subparsers(dest='action',required=True)
    i=sub.add_parser('install');i.add_argument('version');i.add_argument('--target-db',required=True);i.add_argument('--unit',action='append',required=True);i.add_argument('--sudo-systemctl',action='store_true');i.add_argument('--confirm',action='store_true',required=True)
    r=sub.add_parser('recover');r.add_argument('--sudo-systemctl',action='store_true');r.add_argument('--confirm',action='store_true',required=True)
    sub.add_parser('status')
    args=p.parse_args()
    try:
        if sys.platform!='linux': raise UpdateError('Native installation requires Linux/systemd.')
        if any(key.startswith('DB_') or key in ('CAJEER_ENV_FILE','CAJEER_RELEASE_VERSION','APP_URL','BACKUP_DIR') for key in os.environ):
            raise UpdateError('Remove external environment/database/version overrides for native installation.')
        os.umask(0o077)
        installer=Installer(ROOT,Host(getattr(args,'sudo_systemctl',False)))
        fd=installer.lock()
        try:
            result=installer.install(args.version,args.target_db,args.unit) if args.action=='install' else installer.recover() if args.action=='recover' else installer.read() or {'phase':'idle'}
            print(json.dumps(result,indent=2,sort_keys=True))
        finally: os.close(fd)
    except (Exception,KeyboardInterrupt) as error:
        print('Safe update failed: '+str(error),file=sys.stderr)
        return 1
    return 0

if __name__=='__main__':
    sys.exit(main())

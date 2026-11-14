"""Verify the actual shipped distribution, deterministic bytes and tamper rejection."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import zipfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import release
count=0

def check(value,label):
    global count
    assert value,label
    count+=1; print('PASS',label,flush=True)

def rejects(fn):
    try: fn()
    except (ValueError,KeyError,zipfile.BadZipFile): return True
    return False

with tempfile.TemporaryDirectory() as temp:
    p=Path(temp); src=p/'source'; shutil.copytree(ROOT,src,ignore=shutil.ignore_patterns('.git','vendor','node_modules','__pycache__','.env','release-out','.gradle','build','dist'))
    (src/'.env').write_text('SECRET=do-not-publish')
    (src/'storage/logs/test.log').write_text('private log')
    (src/'storage/backups/private.dump').write_text('private dump')
    (src/'public/.env').write_text('PRIVATE=do-not-publish')
    (src/'packages/ts-sdk/node_modules').mkdir(exist_ok=True); (src/'packages/ts-sdk/node_modules/private.js').write_text('private')
    a=p/'a.zip'; b=p/'b.zip'
    meta=release.build(src,a); release.build(src,b)
    check(a.read_bytes()==b.read_bytes(),'deterministic distribution')
    check(a.with_name('a.zip.sha256').read_text().split()[0]==hashlib.sha256(a.read_bytes()).hexdigest(),'sidecar checksum')
    check(not any(name in meta['files'] for name in ('.env','public/.env','storage/logs/test.log','storage/backups/private.dump','packages/ts-sdk/node_modules/private.js')),'private data excluded')
    target=p/'release'; release.verify(a,target)
    check((target/'bin/cajeer').stat().st_mode&0o111!=0,'executable CLI mode')
    env=dict(os.environ,APP_ENV='test',APP_DEBUG='false'); env.pop('CAJEER_ENV_FILE',None)
    check(subprocess.check_output(['php','bin/cajeer','version'],cwd=target,env=env,text=True).strip()=='0.1.5','shipped CLI starts')
    subprocess.run(['php','bin/cajeer','openapi:lint'],cwd=target,env=env,check=True)
    check((target/'api/openapi.yaml').read_bytes()==(target/'openapi.yaml').read_bytes(),'shipped OpenAPI matches runtime')
    check(rejects(lambda:release.verify(a,target)),'nonempty extraction target rejected')
    with zipfile.ZipFile(a) as z: files={n:z.read(n) for n in z.namelist()}
    def bad(name, data, extra=None):
        f=p/'bad.zip'
        with zipfile.ZipFile(f,'w') as z:
            for n,v in files.items(): z.writestr(n,data if n==name else v)
            if extra: z.writestr(extra,b'evil')
        return rejects(lambda:release.verify(f))
    check(bad('core/bootstrap.php',b'<?php echo "tamper";'),'changed payload rejected')
    check(bad(None,b'', '../escape'),'path traversal rejected')
    check(bad(None,b'', 'public/.env'),'secret file rejected')
    check(bad(None,b'', 'unlisted.txt'),'unlisted file rejected')
    (src/'api/openapi.yaml').write_text('{}')
    check(rejects(lambda:release.build(src,p/'bad-build.zip')),'inconsistent API versions rejected')
    (src/'api/openapi.yaml').write_bytes((ROOT/'api/openapi.yaml').read_bytes())
    (src/'public/assets/evil.js').symlink_to('/etc/passwd')
    check(rejects(lambda:release.build(src,p/'bad-build.zip')),'source symlink rejected')
    (src/'public/assets/evil.js').unlink()
    subprocess.run(['git','init','-q'],cwd=src,check=True)
    staged=[n for n in meta['files'] if n not in release.REQUIRED and (src/n).is_file()]
    subprocess.run(['git','add','-f','--',*staged],cwd=src,check=True)
    (src/'public/assets/untracked-private.js').write_text('private')
    overlay=release.build(src,p/'overlay.zip')
    check(release.REQUIRED <= overlay['files'].keys() and 'public/assets/untracked-private.js' not in overlay['files'], 'git overlay includes required new files only')
print(f'Release packaging: {count} checks passed')

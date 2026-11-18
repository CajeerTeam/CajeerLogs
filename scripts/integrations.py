#!/usr/bin/env python3
"""Run shipping SDK/importer binaries from a verified release ZIP, not a checkout.

The output is hash-bound execution evidence, never a certification by itself.
Install operations must succeed; no optional skips, mocks of clients, or synthetic passes.
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

VERSION = '0.7.2'
STAGES = (
    'composer-dependencies', 'typescript-dependencies', 'typescript-package',
    'typescript-installed-package', 'python-wheel', 'python-installed-import', 'kotlin-package',
    'sdk-http-wire', 'sdk-async-wire', 'php-monolog-wire',
    'python-durable-spool', 'typescript-durable-spool', 'kotlin-queue-wire',
    'nginx-incremental-import', 'aapanel-multi-source',
)


def run_stage(report, name, cmd, root, env, *, timeout=360):
    stage = {'name': name, 'status': 'failed'}
    report['stages'].append(stage)
    begin = time.monotonic()
    try:
        result = subprocess.run(cmd, cwd=root, env=env, timeout=timeout, check=False)
        stage['exit_code'] = result.returncode
        if result.returncode:
            raise RuntimeError(f'{name} failed with exit code {result.returncode}')
        stage['status'] = 'passed'
    finally:
        stage['seconds'] = round(time.monotonic() - begin, 3)


def execute(archive, report):
    info = release.verify(archive)
    if info['version'] != VERSION:
        raise ValueError('Expected the 0.7.2 release archive')
    report.update(version=VERSION, archive_sha256=hashlib.sha256(archive.read_bytes()).hexdigest())
    with tempfile.TemporaryDirectory(prefix='cl-sdk-release-') as tmp:
        root = Path(tmp)/'app'
        release.verify(archive, root)
        env = os.environ.copy()
        # Prevent the released package from reading an operator's real DB credentials.
        for key in list(env):
            if key.startswith(('DB_', 'PG', 'CAJEER_', 'AWS_')) or key in ('GITHUB_TOKEN','GH_TOKEN','OPENAI_API_KEY'):
                env.pop(key, None)
        env.update(APP_ENV='test', CAJEER_TEST_DATABASE='0')
        run_stage(report, STAGES[0], ['composer','install','--no-interaction','--prefer-dist','--no-progress'],root,env,timeout=600)
        run_stage(report, STAGES[1], ['npm','--prefix','packages/ts-sdk','install','--no-audit','--no-fund'],root,env,timeout=600)
        run_stage(report, STAGES[2], ['npm','--prefix','packages/ts-sdk','run','check'],root,env,timeout=360)
        # Packaging alone is not enough: install the tarball in a clean Node
        # project and import the shipping ESM API, not a checkout source file.
        env['PATH'] = str(root/'packages/ts-sdk/node_modules/.bin') + os.pathsep + env.get('PATH','')
        packdir=Path(tmp)/'npmpack'; packdir.mkdir()
        subprocess.run(['npm','pack',str(root/'packages/ts-sdk'),'--pack-destination',str(packdir)],
                       check=True,cwd=root,env=env,timeout=120)
        packed=list(packdir.glob('*.tgz'))
        if len(packed)!=1:raise ValueError('Expected exactly one npm package tarball')
        consumer=Path(tmp)/'npm-consumer';consumer.mkdir()
        consumer.joinpath('package.json').write_text('{"private":true,"type":"module"}')
        run_stage(report, STAGES[3], ['npm','install','--no-audit','--no-fund',str(packed[0])],consumer,env,timeout=120)
        node_test="import {CajeerLogsClient,DurableBatchDelivery} from '@cajeer/logs-sdk'; if(typeof CajeerLogsClient!=='function'||typeof DurableBatchDelivery!=='function')process.exit(1)"
        subprocess.run(['node','--input-type=module','-e',node_test],cwd=consumer,env=env,timeout=30,check=True)
        # Build a real wheel from the shipped package, then import from an isolated venv.
        wheels=Path(tmp)/'wheels';wheels.mkdir()
        run_stage(report, STAGES[4], [sys.executable,'-m','pip','wheel','--no-deps','--wheel-dir',str(wheels),str(root/'clients/python')],root,env,timeout=600)
        venv=Path(tmp)/'venv'
        subprocess.run([sys.executable,'-m','venv',str(venv)],check=True,timeout=60)
        py=venv/'bin/python'
        if os.name=='nt': py=venv/'Scripts/python.exe'
        wheel=list(wheels.glob('cajeer_logs_client-*.whl'))
        if len(wheel)!=1:raise ValueError('Exactly one distributable Python SDK wheel required')
        install=subprocess.run([str(py),'-m','pip','install','--no-deps',str(wheel[0])],cwd=tmp,env=env,timeout=120)
        if install.returncode:raise RuntimeError('Cannot install released Python wheel')
        run_stage(report, STAGES[5], [str(py),'-c',
            'import cajeer_logs_client,cajeer_logs_collector,cajeer_logging,cajeer_batch_spool,cajeer_delivery_spool,cajeer_spool_cli; print(cajeer_logs_client.__file__)'],
            tmp,env,timeout=30)
        run_stage(report, STAGES[6], ['gradle','-p','clients/java-kotlin','build','publishToMavenLocal','--no-daemon'],root,env,timeout=900)
        runs = (
            ['python3','tests/sdk_wire.py'],
            ['python3','tests/sdk_async_wire.py'],
            ['python3','tests/sdk_buffered_wire.py'],
            ['python3','tests/python_sdk_stable.py'],
            ['node','--test','packages/ts-sdk/test/durable.test.mjs'],
            ['python3','tests/kotlin_sdk_stable.py'],
            ['python3','tests/nginx_import_stable.py'],
            ['python3','tests/aapanel_integration_stable.py'],
        )
        for name, cmd in zip(STAGES[7:],runs):
            run_stage(report,name,cmd,root,env,timeout=300)


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('archive',type=Path)
    p.add_argument('--output',type=Path,required=True)
    args=p.parse_args()
    report={'format':1,'scope':'sdk-integrations','version':VERSION,'status':'failed',
            'created_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'stages':[]}
    try:
        execute(args.archive.resolve(), report)
        report['status']='passed'
    except (Exception,KeyboardInterrupt) as error:
        report['error_type']=type(error).__name__
        print(f'SDK/integrations certification failed: {type(error).__name__}: {error}',file=sys.stderr)
    finally:
        args.output.parent.mkdir(parents=True,exist_ok=True)
        # Write through a unique temporary file to avoid accepting partial evidence.
        fd,tmp=tempfile.mkstemp(prefix='.sdk-evidence-',dir=args.output.parent)
        try:
            with os.fdopen(fd,'w') as f:
                json.dump(report,f,indent=2,sort_keys=True);f.write('\n');f.flush();os.fsync(f.fileno())
            os.replace(tmp,args.output)
        finally:
            Path(tmp).unlink(missing_ok=True)
    return 0 if report['status']=='passed' else 1

if __name__=='__main__':sys.exit(main())

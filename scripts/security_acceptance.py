#!/usr/bin/env python3
"""Execute security regression suites from the exact release archive.

This is a CI security gate, NOT a third-party pentest or vulnerability-free
attestation. Tests run in a fresh isolated extract with no deployment secrets.
The report is usable only for the matching SHA-256 release artifact.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import tempfile
import time

import release

VERSION = '0.9.6'
# These are executable security tests, not checks for the existence of files.
STAGES = (
    ('deployment-policy', ('php', 'tests/deployment_security.php'), 20),
    ('deployment-tls', (sys.executable, 'tests/deployment_tls.py'), 2),
    ('secure-installer', ('php', 'tests/secure_installer.php'), 10),
    ('production-installer', ('php', 'tests/production_installer.php'), 17),
    ('ingest-authorization', ('php', 'tests/ingest_authorization.php'), 25),
    ('security-hardening', ('php', 'tests/security_hardening.php'), 30),
    ('security-operations', ('php', 'tests/security_operations.php'), 25),
    ('release-version-guard', ('php', 'tests/release_runtime.php'), 33),
)
HASH = re.compile(r'^[a-f0-9]{64}$')


def run_stage(command, root, environment, timeout):
    """Bound execution and output; terminate all subprocess descendants on timeout."""
    start = time.monotonic()
    process = subprocess.Popen(command, cwd=root, env=environment, start_new_session=True,
                               stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    try:
        try:
            stdout, _ = process.communicate(timeout=timeout)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            stdout, _ = process.communicate()
            raise RuntimeError('Security test exceeded its timeout')
        # The digest, not the potentially sensitive logs, is published as evidence.
        if len(stdout) > 1024 * 1024:
            raise RuntimeError('Security test output exceeds maximum size')
        return {'exit_code': process.returncode, 'seconds': round(max(.000001, time.monotonic()-start), 3),
                'assertions': sum(line.startswith(b'PASS ') for line in stdout.splitlines()),
                'log_sha256': hashlib.sha256(stdout).hexdigest()}
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()


def safe_environment(home):
    # Whitelist system-only settings. Never accidentally pass production access to tests.
    allowed = ('PATH', 'SYSTEMROOT', 'WINDIR', 'LANG', 'LC_ALL', 'TZ', 'TMPDIR')
    env = {key: os.environ[key] for key in allowed if key in os.environ}
    env.update(APP_ENV='test', APP_DEBUG='false', CAJEER_TEST_DATABASE='0',
               HOME=str(home), PYTHONDONTWRITEBYTECODE='1')
    return env


def atomic_report(path, report):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(prefix='.security-acceptance-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as fp:
            json.dump(report, fp, indent=2, sort_keys=True, allow_nan=False)
            fp.write('\n')
            fp.flush()
            os.fsync(fp.fileno())
        os.replace(temp, path)
    finally:
        Path(temp).unlink(missing_ok=True)


def run(archive, output, timeout=120):
    report = {'format': 1, 'scope': 'security-acceptance', 'status': 'failed',
              'version': VERSION, 'stages': [],
              'created_at': dt.datetime.now(dt.timezone.utc).isoformat()}
    try:
        if not (15 <= timeout <= 600):
            raise ValueError('Security stage timeout out of permitted range')
        info = release.verify(archive)
        if info.get('version') != VERSION:
            raise ValueError('Expected ' + VERSION + ' archive')
        report['archive_sha256'] = hashlib.sha256(archive.read_bytes()).hexdigest()
        with tempfile.TemporaryDirectory(prefix='cajeer-security-') as directory:
            temp = Path(directory)
            root = temp / 'release'
            home = temp / 'home'
            home.mkdir(mode=0o700)
            release.verify(archive, root)
            environment = safe_environment(home)
            for name, cmd, minimum in STAGES:
                stage = {'name': name, 'status': 'failed'}
                report['stages'].append(stage)
                result = run_stage(cmd, root, environment, timeout)
                stage.update(result)
                if result['exit_code'] != 0 or result['assertions'] < minimum:
                    raise RuntimeError(name + ' failed required executable assertions')
                stage['status'] = 'passed'
                print(f'PASS {name}: {result["assertions"]} assertions', flush=True)
        report['status'] = 'passed'
    except (Exception, KeyboardInterrupt) as error:
        report['error_type'] = type(error).__name__
        print('Security acceptance failed: ' + type(error).__name__, file=sys.stderr)
    finally:
        atomic_report(output, report)
    return report['status'] == 'passed'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--timeout', type=int, default=120)
    args = parser.parse_args()
    return 0 if run(args.archive.resolve(), args.output.resolve(), args.timeout) else 1


if __name__ == '__main__':
    raise SystemExit(main())

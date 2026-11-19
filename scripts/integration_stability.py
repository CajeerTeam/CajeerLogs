#!/usr/bin/env python3
"""Exercise real PHP/HTTP SDK, importers and transports from an immutable release ZIP.

Each pass verifies and unpacks the same SHA-256-bound distribution into a fresh
private workspace. Failed tests, crashes, timeouts and incomplete passes fail
closed; no test is replaced by a synthetic result or silently skipped.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time

import release

STAGES = (
    ('php-http-runtime', ('python3', 'tests/http_runtime.py')),
    ('php-signed-batching', ('python3', 'tests/sdk_buffered_wire.py')),
    ('nginx-incremental-transport', ('python3', 'tests/nginx_import_stable.py')),
    ('aapanel-multi-source-transport', ('python3', 'tests/aapanel_integration_stable.py')),
    ('clickhouse-http-migration-transport', ('python3', 'tests/clickhouse_http.py')),
    ('s3-signed-storage-transport', ('python3', 'tests/s3_storage.py')),
)
DEFAULT_TIMEOUT = 300


def clean_environment(workspace: Path) -> dict[str, str]:
    """Prevent a fixture from accidentally connecting to installed databases/services."""
    env = {k: v for k, v in os.environ.items()
           if not (k.startswith(('DB_', 'PG', 'AWS_', 'S3_', 'CAJEER_')) or k in
                   ('DATABASE_URL', 'GITHUB_TOKEN', 'GH_TOKEN', 'OPENAI_API_KEY', 'HOME', 'TMPDIR'))}
    home = workspace / 'home'
    scratch = workspace / 'tmp'
    home.mkdir(mode=0o700)
    scratch.mkdir(mode=0o700)
    env.update(HOME=str(home), TMPDIR=str(scratch), XDG_CACHE_HOME=str(home / '.cache'),
               APP_ENV='test', CAJEER_TEST_DATABASE='0', PYTHONDONTWRITEBYTECODE='1',
               PYTHONHASHSEED='0')
    return env


def run_command(command: tuple[str, ...], root: Path, env: dict[str, str], log: Path,
                timeout: int) -> tuple[int, float, str]:
    """Kill the entire test process group on timeout, including spawned HTTP servers."""
    start = time.monotonic()
    with log.open('wb') as output:
        process = subprocess.Popen(command, cwd=root, env=env, stdout=output,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        try:
            code = process.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGTERM)
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait(timeout=5)
            code = 124
        # Children such as HTTP fixtures may outlive a successful parent.
        # The isolated process group can be cleaned without affecting CI.
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        output.flush()
        os.fsync(output.fileno())
    return code, round(max(0.000001, time.monotonic() - start), 3), hashlib.sha256(log.read_bytes()).hexdigest()


def execute(archive: Path, passes: int, timeout: int, report: dict) -> None:
    info = release.verify(archive)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    report.update(version=info['version'], archive_sha256=digest, passes=passes)
    if info['version'] != '0.9.3':
        raise ValueError('Expected the 0.9.3 distribution')
    for run in range(1, passes + 1):
        with tempfile.TemporaryDirectory(prefix='cl-integration-stability-') as directory:
            temp = Path(directory)
            root = temp / 'app'
            release.verify(archive, root)
            env = clean_environment(temp)
            for name, command in STAGES:
                stage = {'pass': run, 'name': name, 'status': 'failed'}
                report['stages'].append(stage)
                code, seconds, digest_log = run_command(command, root, env, temp / (name + '.log'), timeout)
                stage.update(exit_code=code, seconds=seconds, log_sha256=digest_log)
                if code != 0:
                    raise RuntimeError(f'{name} pass {run} terminated with {code}')
                stage['status'] = 'passed'
                print(f'PASS pass={run}/{passes} {name} ({seconds}s)', flush=True)


def write_report(path: Path, report: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.integration-', mode='w',
                                     encoding='utf-8', delete=False) as stream:
        temp = Path(stream.name)
        json.dump(report, stream, sort_keys=True, indent=2)
        stream.write('\n')
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(temp, path)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--passes', type=int, choices=(1, 2, 3), default=2)
    parser.add_argument('--timeout', type=int, default=DEFAULT_TIMEOUT)
    args = parser.parse_args()
    report = dict(format=1, scope='integration-stability', status='failed', stages=[],
                  created_at=dt.datetime.now(dt.timezone.utc).isoformat())
    try:
        if not 30 <= args.timeout <= 900:
            raise ValueError('Per-stage timeout must be between 30 and 900 seconds')
        execute(args.archive.resolve(), args.passes, args.timeout, report)
        report['status'] = 'passed'
    except (Exception, KeyboardInterrupt) as e:
        report['error_type'] = type(e).__name__
        print(f'Integration stability failed: {type(e).__name__}', file=sys.stderr)
    finally:
        write_report(args.output, report)
    return 0 if report['status'] == 'passed' else 1


if __name__ == '__main__':
    raise SystemExit(main())

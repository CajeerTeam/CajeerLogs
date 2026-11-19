#!/usr/bin/env python3
"""Stop and restart disposable CI PostgreSQL/ClickHouse containers while real events are in flight.

This is intentionally unavailable to production operators: use only GitHub Actions
service container ids with explicit opt-in. The script always restarts a stopped
container in a finally block, including when a PHP assertion fails.
"""
import argparse
import base64
import json
import urllib.request
import urllib.error
from urllib.parse import urlsplit
from contextlib import contextmanager
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

ENV_REQUIRED = ('DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
                'CLICKHOUSE_DSN', 'CLICKHOUSE_DATABASE', 'CLICKHOUSE_USERNAME', 'CLICKHOUSE_PASSWORD')
SERVICE_IMAGES = {'postgres': 'postgres:17', 'clickhouse': 'clickhouse/clickhouse-server:25.8'}


def preflight(env):
    if not (env.get('APP_ENV') == 'test' and env.get('CAJEER_TEST_DATABASE') == '1'
            and env.get('CAJEER_TEST_CLICKHOUSE') == '1'
            and env.get('CAJEER_TEST_SERVICE_STOP') == '1'):
        raise ValueError('Real service-stop test requires explicit disposable CI opt-in')
    if env.get('GITHUB_ACTIONS') != 'true' or env.get('GITHUB_JOB') != 'clickhouse-integration':
        raise ValueError('Container stop/start is allowed only inside the dedicated GitHub CI job')
    if env.get('DB_HOST') not in ('127.0.0.1', 'localhost') or urlsplit(env.get('CLICKHOUSE_DSN','')).hostname not in ('127.0.0.1', 'localhost'):
        raise ValueError('Live failure recovery can only address local ephemeral CI services')
    if any(not env.get(key) for key in ENV_REQUIRED):
        raise ValueError('Disposable PostgreSQL and ClickHouse credentials are mandatory')
    if env['DB_DATABASE'].lower() in ('postgres', 'template0', 'template1'):
        raise ValueError('Refusing to stop containers for a system database')
    containers = {}
    for service in SERVICE_IMAGES:
        name = 'CAJEER_TEST_' + service.upper() + '_CONTAINER'
        identifier = env.get(name, '')
        if not re.fullmatch(r'[a-f0-9]{64}', identifier):
            raise ValueError('An exact full-length disposable service container ID is required: ' + name)
        containers[service] = identifier
    if containers['postgres'] == containers['clickhouse']:
        raise ValueError('Database services must have distinct container IDs')
    return containers


def docker(*args, timeout=35):
    return subprocess.run(['docker', *args], text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=timeout, check=True).stdout.strip()


def check_service(identifier, image):
    info = docker('inspect', '--format', '{{.Id}}|{{.Config.Image}}|{{.State.Running}}', identifier)
    parts = info.split('|')
    if len(parts) != 3 or parts != [identifier, image, 'true']:
        raise ValueError('Service container does not match the exact expected running CI image')


@contextmanager
def stopped_service(identifier):
    stop_attempted = False
    try:
        stop_attempted = True
        docker('stop', '--time', '5', identifier, timeout=25)
        if docker('inspect', '--format', '{{.State.Running}}', identifier) != 'false':
            raise RuntimeError('Service did not stop')
        yield
    finally:
        if stop_attempted:
            # Even a timeout in `docker stop` can leave the service stopped.
            # Never leave a stopped CI database behind on an exceptional exit.
            state = docker('inspect', '--format', '{{.State.Running}}', identifier)
            if state == 'false':
                docker('start', identifier, timeout=45)
            elif state != 'true':
                raise RuntimeError('Unknown database service state after stop attempt')
            if docker('inspect', '--format', '{{.State.Running}}', identifier) != 'true':
                raise RuntimeError('Service did not restart')


def pg_probe(env, expect_ready=True):
    pg = dict(env, PGHOST=env['DB_HOST'], PGPORT=env.get('DB_PORT', '5432'),
              PGDATABASE=env['DB_DATABASE'], PGUSER=env['DB_USERNAME'],
              PGPASSWORD=env['DB_PASSWORD'], PGCONNECT_TIMEOUT='2')
    result = subprocess.run(['psql', '-X', '-A', '-t', '-v', 'ON_ERROR_STOP=1',
                             '-c', 'SELECT 1'], env=pg, stdout=subprocess.PIPE,
                            stderr=subprocess.DEVNULL, text=True, timeout=6)
    ready = result.returncode == 0 and result.stdout.strip() == '1'
    if ready != expect_ready:
        raise AssertionError('PostgreSQL connectivity did not match expected stop/recovery state')


def retry_until_ready(probe, seconds=45):
    until = time.monotonic() + seconds
    while True:
        try:
            probe()
            return
        except (AssertionError, subprocess.TimeoutExpired, subprocess.CalledProcessError, urllib.error.URLError, OSError):
            if time.monotonic() >= until:
                raise RuntimeError('Service did not recover within the readiness deadline')
            time.sleep(0.4)


def clickhouse_probe(env):
    uri = env['CLICKHOUSE_DSN'].rstrip('/') + '/?query=SELECT%201'
    authorization = base64.b64encode((env['CLICKHOUSE_USERNAME'] + ':' + env['CLICKHOUSE_PASSWORD']).encode()).decode()
    request = urllib.request.Request(uri, headers={'Authorization': 'Basic ' + authorization})
    with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(request, timeout=3) as response:
        if response.status != 200 or response.read(64).strip() != b'1':
            raise AssertionError('ClickHouse service is not yet ready')


def phase(root, name, state, env):
    result = subprocess.run(['php', 'tests/component_recovery_database.php', name, str(state)],
                            cwd=root, env=env, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=90)
    if result.returncode or 'PASS ' not in result.stdout:
        raise RuntimeError('Real database phase failed: ' + name)
    checks = 0
    for line in result.stdout.splitlines():
        if line.startswith('PASS '):
            checks += 1
            print('PASS ' + name + ': ' + line[5:], flush=True)
    return checks


def run(root, env):
    ids = preflight(env)
    for service in SERVICE_IMAGES:
        check_service(ids[service], SERVICE_IMAGES[service])
    pg_probe(env)
    clickhouse_probe(env)
    with tempfile.TemporaryDirectory(prefix='cajeer-service-stop-') as tmp:
        state = Path(tmp) / 'state.json'
        try:
            phase(root, 'seed', state, env)
            with stopped_service(ids['clickhouse']):
                pg_probe(env)
                phase(root, 'outage', state, dict(env, CLICKHOUSE_TIMEOUT_SECONDS='2'))
            retry_until_ready(lambda: clickhouse_probe(env))
            phase(root, 'recover', state, env)
            # Keep PostgreSQL state committed while its *actual service* is stopped.
            with stopped_service(ids['postgres']):
                pg_probe(env, expect_ready=False)
                print('PASS real PostgreSQL service stopped and refused connections', flush=True)
            retry_until_ready(lambda: pg_probe(env))
            phase(root, 'verify', state, env)
        finally:
            if state.is_file():
                phase(root, 'cleanup', state, env)
    print('PASS real service stop/start and queue/analytics recovery', flush=True)
    print('RECOVERY_JSON:' + json.dumps({'postgres_stopped': True, 'clickhouse_stopped': True,
                                       'worker_crash_recovered': True, 'replay_verified': True,
                                       'data_reconciled': True, 'isolated_projects': 2}, sort_keys=True), flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    try:
        run(args.root.resolve(), dict(os.environ))
        return 0
    except (Exception, KeyboardInterrupt) as e:
        print('Service recovery failed: ' + type(e).__name__, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

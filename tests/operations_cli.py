"""Exercise the shipped operator CLI through real PHP boot with an unavailable database.

The fixture never contacts a production host or stores incident evidence in the source tree.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def run(root, env, *args):
    return subprocess.run(['php', 'bin/cajeer', *args], cwd=root, env=env,
                          text=True, capture_output=True, timeout=35)


with tempfile.TemporaryDirectory(prefix='cajeer-ops-cli-') as temp:
    root = Path(temp) / 'app'
    shutil.copytree(ROOT, root, ignore=shutil.ignore_patterns('.env','vendor','node_modules','__pycache__', '*.pyc', 'incident-reports'))
    env = os.environ.copy()
    for key in list(env):
        if key.startswith(('BACKUP_', 'CAJEER_', 'DB_', 'PG', 'SECURITY_', 'INGEST_', 'OPERATIONS_')):
            env.pop(key)
    env.update(APP_ENV='test', APP_URL='http://localhost:8080', APP_DEBUG='false',
               DB_HOST='127.0.0.1', DB_PORT='1', DB_DATABASE='ops_unavailable',
               DB_USERNAME='ops_test', DB_PASSWORD='private-test-password',
               DB_SSLMODE='disable', DB_REQUIRE_TLS='false')
    version = run(root, env, 'version')
    assert version.returncode == 0 and version.stdout.strip() == '1.0.3', version.stderr
    triage = run(root, env, 'operations:triage')
    assert triage.returncode == 2 and not triage.stderr, triage.stderr
    result = json.loads(triage.stdout)
    assert result['status'] == 'critical' and result['version'] == '1.0.3'
    assert result['checks']['database_readiness'] is False
    assert result['scope'] == 'operations-triage' and result['critical']
    assert 'private-test-password' not in triage.stdout
    assert all((root / issue['runbook']).is_file() for issue in result['critical'])
    print('PASS actual CLI reports database outage and actionable local runbooks')

    incident = run(root, env, 'operations:incident')
    assert incident.returncode == 2 and not incident.stderr, incident.stderr
    record = json.loads(incident.stdout)
    path = Path(record['report'])
    assert path.parent == root / 'storage/incident-reports'
    assert path.is_file() and path.stat().st_mode & 0o777 == 0o600
    assert path.parent.stat().st_mode & 0o777 == 0o700
    saved = json.loads(path.read_text())
    assert saved['status'] == 'critical' and saved['checks']['database_readiness'] is False
    assert 'private-test-password' not in path.read_text()
    another = run(root, env, 'operations:incident')
    assert another.returncode == 2
    path2 = Path(json.loads(another.stdout)['report'])
    assert path != path2 and path.is_file() and path2.is_file()
    print('PASS actual CLI persists two distinct private reports and preserves failure exit status')

    bad = run(root, env, 'operations:triage', 'one', 'two')
    assert bad.returncode != 0 and not bad.stdout
    print('PASS invalid operator arguments rejected')

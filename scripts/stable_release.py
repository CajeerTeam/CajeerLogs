#!/usr/bin/env python3
"""Reject mixed-version or insecure CajeerLogs production bundles.

Runs against the verified release ZIP and its *actual shipped files*, not declarations
from the checkout. Does not claim integration tests or a signature passed.
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import tomllib

import release

VERSION = '0.9.7'


class InvalidRelease(ValueError):
    pass


def require(condition: bool, label: str) -> None:
    if not condition:
        raise InvalidRelease(label)


def identity(root: Path) -> dict:
    """Check runnable PHP, published SDK versions, browser imports and Compose.

    Deliberately does not consult git, env vars or an untrusted supplied version.
    """
    load = lambda p: (root / p).read_text(encoding='utf-8')
    obj = lambda p: json.loads(load(p))
    info = obj('release.json')
    require(info.get('name') == 'CajeerLogs' and info.get('version') == VERSION,
            'Release metadata identity mismatch')
    require(info.get('channel') == 'stable', 'Release is not marked stable')
    require(obj('api/openapi.yaml')['info']['version'] == VERSION and load('api/openapi.yaml') == load('openapi.yaml'),
            'Published API v1 version or source mismatch')
    require(obj('admin/package.json').get('version') == VERSION, 'Admin package version mismatch')
    require(obj('packages/ts-sdk/package.json').get('version') == VERSION, 'TypeScript SDK version mismatch')
    require(obj('packages/php-sdk/composer.json').get('version') == VERSION, 'PHP SDK version mismatch')
    require(tomllib.loads(load('clients/python/pyproject.toml'))['project']['version'] == VERSION,
            'Python SDK version mismatch')
    require(re.search(r'^version\s*=\s*"' + re.escape(VERSION) + r'"\s*$',
                      load('clients/java-kotlin/build.gradle.kts'), re.M) is not None,
            'Kotlin SDK version mismatch')

    cli = subprocess.run(['php', 'bin/cajeer', 'version'], cwd=root, capture_output=True,
                         text=True, timeout=15, check=False)
    require(cli.returncode == 0 and cli.stdout.strip() == VERSION, 'Shipped PHP CLI version mismatch')
    # Browser requests are served from the immutable ZIP. A single stale JS import
    # silently loads old code after an otherwise successful rolling deployment.
    js = list((root / 'public/admin/assets').rglob('*.js'))
    css = list((root / 'public/admin/assets').rglob('*.css'))
    require(bool(js) and bool(css), 'Browser bundle missing')
    count = 0
    for path in js + css + [root / 'admin/index.html', root / 'public/admin/index.html']:
        if not path.exists():
            continue
        for tag in re.findall(r'\?v=(\d+\.\d+\.\d+)', path.read_text(encoding='utf-8')):
            require(tag == VERSION, 'Old browser cache key in ' + path.relative_to(root).as_posix())
            count += 1
    require(count >= 20, 'Browser module references were not verified')
    compose = load('compose.production.yaml')
    for image in ('cajeerlogs:' + VERSION, 'cajeerlogs-gateway:' + VERSION):
        require(re.search(r'^\s*image:\s*' + re.escape(image) + r'\s*$', compose, re.M) is not None,
                'Production image tag mismatch: ' + image)
    require('cajeerlogs:0.7.5' not in compose and 'cajeerlogs-gateway:0.7.5' not in compose,
            'Legacy production image tag')
    for setting in ("APP_ENV: production", "CAJEER_RELEASE_VERSION: '0.9.7'", "APP_DEBUG: 'false'", "read_only: true", "no-new-privileges:true",
                    "SECURITY_2FA_REQUIRED_FOR_ADMINS: 'true'", "PROMETHEUS_TOKEN: ${PROMETHEUS_TOKEN:?Set PROMETHEUS_TOKEN}"):
        require(setting in compose, 'Missing production security control: ' + setting)
    docker = load('deploy/production/Dockerfile')
    require('USER www-data' in docker and 'pdo_pgsql' in docker and 'sodium' in docker,
            'Expected restricted production PHP runtime')
    return {'version': VERSION, 'checks': ['php-cli', 'admin-package', 'typescript-sdk', 'php-sdk',
            'python-sdk', 'kotlin-sdk', 'browser-assets', 'production-images', 'production-security'],
            'versioned_browser_references': count}


def verify(archive: Path) -> dict:
    with tempfile.TemporaryDirectory(prefix='cajeer-release-identity-') as temp:
        root = Path(temp) / 'extracted'
        meta = release.verify(archive, root)
        require(meta.get('version') == VERSION, 'Archive manifest version mismatch')
        checked = subprocess.run([sys.executable, 'scripts/api_contract.py', '--root', str(root)],
                                 cwd=root, capture_output=True, text=True, timeout=90)
        require(checked.returncode == 0, 'Published API v1 contract failed: ' + checked.stderr[-1200:])
        return identity(root)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('archive', type=Path)
    args = ap.parse_args()
    try:
        print(json.dumps(verify(args.archive), sort_keys=True))
    except (InvalidRelease, OSError, ValueError, subprocess.SubprocessError) as error:
        print('Stable release identity rejected: ' + str(error), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())

#!/usr/bin/env python3
"""Resolve, validate and *install* production release dependencies.

`refresh` is an explicit, network-backed maintainer operation. It writes the actual
package-manager lockfiles; no metadata or hashes are guessed. `verify --install`
checks all committed locks, installs those exact packages, runs SDK tests and
checks pinned OCI manifests against the registry. Failure is always fatal.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import tomllib
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HEX = re.compile(r'^[a-fA-F0-9]{64}$')
REF = re.compile(r'(?P<image>[\w./-]+(?::[\w.-]+)?)@sha256:(?P<digest>[a-f0-9]{64})$')
GRADLE_LOCK = 'clients/java-kotlin/gradle.lockfile'
GRADLE_VERIFY = 'clients/java-kotlin/gradle/verification-metadata.xml'


def inspection(files: dict[str, bytes]) -> dict:
    """Conservatively validate *the archived bytes*, not checkout declarations.

    Installed package validation is separate: `verify --install` invokes the real
    package managers. A well-formed lockfile alone is not proof of a clean install.
    """
    issues: list[str] = []
    declarations: list[dict] = []
    resolved: list[dict] = []

    def dep(ecosystem: str, name: str, constraint: str, scope: str):
        declarations.append(dict(ecosystem=ecosystem, name=name, constraint=constraint, scope=scope))

    def check(bool_value: bool, msg: str):
        if not bool_value:
            issues.append(msg)

    # Composer root + transitive lock: reject empty locks, missing dev dependencies,
    # duplicate entries, unsigned source refs and manifest root mismatches.
    composer = json.loads(files['composer.json'])
    top = set()
    for scope in ('require', 'require-dev'):
        for name, constraint in sorted(composer.get(scope, {}).items()):
            if name != 'php' and not name.startswith(('ext-', 'lib-')):
                dep('composer', name, str(constraint), scope)
                top.add(name)
    if 'composer.lock' not in files:
        issues.append('composer.lock missing')
    else:
        try:
            lock = json.loads(files['composer.lock'])
            check(bool(re.fullmatch('[a-f0-9]{32}', str(lock.get('content-hash', '')))), 'composer.lock content-hash invalid')
            all_pkgs = lock.get('packages', []) + lock.get('packages-dev', [])
            seen = set()
            check(bool(all_pkgs), 'composer.lock has no resolved packages')
            for pkg in all_pkgs:
                name = pkg.get('name', '')
                check(name not in seen, f'Composer duplicate package {name}')
                seen.add(name)
                ref = pkg.get('dist', {}).get('reference') or pkg.get('source', {}).get('reference')
                check(bool(name and pkg.get('version') and ref and re.fullmatch(r'[a-f0-9]{40,64}', str(ref), re.I)),
                      f'Composer package is not immutable: {name}')
                resolved.append(dict(ecosystem='composer', name=name, version=str(pkg.get('version','')), reference=str(ref or '')))
            for name in sorted(top - seen):
                issues.append(f'Composer root requirement missing in lock: {name}')
        except (ValueError, TypeError, KeyError) as error:
            issues.append(f'composer.lock invalid: {error}')

    # npm lockfile v3 has integrity for all downloaded tarballs and a copy of the
    # top-level manifest. Reject files that merely contain a few fake entries.
    npm = json.loads(files['packages/ts-sdk/package.json'])
    for scope in ('dependencies', 'devDependencies'):
        for name, constraint in sorted(npm.get(scope, {}).items()):
            dep('npm', name, str(constraint), scope)
    lock_name = 'packages/ts-sdk/package-lock.json'
    if lock_name not in files:
        issues.append(f'{lock_name} missing')
    else:
        try:
            lock = json.loads(files[lock_name]); packages = lock.get('packages', {})
            check(lock.get('lockfileVersion') == 3, 'npm lockfileVersion must equal 3')
            check(lock.get('name') == npm.get('name') and lock.get('version') == npm.get('version'), 'npm lock root name/version mismatch')
            top_pkg = packages.get('', {})
            for field in ('dependencies', 'devDependencies'):
                check(top_pkg.get(field, {}) == npm.get(field, {}), f'npm root {field} mismatch')
            check(len(packages) > 1, 'npm transitive dependency graph missing')
            for path, pkg in sorted(packages.items()):
                if path == '':
                    continue
                if pkg.get('link'):
                    issues.append(f'npm symlinked dependency in release lock: {path}')
                    continue
                integrity = str(pkg.get('integrity',''))
                check(bool(pkg.get('version') and pkg.get('resolved') and re.fullmatch(r'sha(?:512|384|256)-[a-zA-Z0-9+/]+={0,2}', integrity)),
                      f'npm missing pinned version/resolved/SRI: {path}')
                if pkg.get('resolved'):
                    check(str(pkg['resolved']).startswith('https://registry.npmjs.org/'),
                          f'npm dependency URL outside registry: {path}')
                resolved.append(dict(ecosystem='npm', name=path.removeprefix('node_modules/'),
                                     version=str(pkg.get('version','')), integrity=integrity))
            for name in (*npm.get('dependencies', {}), *npm.get('devDependencies', {})):
                check('node_modules/' + name in packages, f'npm missing direct dependency: {name}')
        except (ValueError, TypeError, KeyError) as error:
            issues.append(f'{lock_name} invalid: {error}')

    py = tomllib.loads(files['clients/python/pyproject.toml'].decode())
    for req in py['build-system'].get('requires', []):
        dep('pypi-build', req.split('=')[0], req, 'build')
        check(bool(re.fullmatch(r'[A-Za-z0-9_.-]+==[0-9][A-Za-z0-9.+!-]*', req)),
              f'Python build dependency not exact pinned: {req}')
    gradle = files['clients/java-kotlin/build.gradle.kts'].decode()
    for plugin, ver in re.findall(r'kotlin\("([\w-]+)"\)\s+version\s+"([^"]+)"', gradle):
        dep('gradle-plugin', f'org.jetbrains.kotlin.{plugin}', ver, 'build')
    check('lockAllConfigurations()' in gradle, 'Gradle dependency locking not enabled')
    if GRADLE_LOCK not in files:
        issues.append(f'{GRADLE_LOCK} missing')
    else:
        contents = files[GRADLE_LOCK].decode()
        deps = [ln for ln in contents.splitlines() if ln and not ln.startswith('#') and not ln.startswith('empty=')]
        check(bool(deps) and all(re.fullmatch(r'[\w.-]+:[\w.-]+:[^=\s]+=[\w,.-]+', ln) for ln in deps),
              'Gradle lockfile lacks resolved modules/configurations')
        for ln in deps:
            if '=' in ln:
                resolved.append(dict(ecosystem='gradle', name=ln.split('=')[0].rsplit(':',1)[0],
                                     version=ln.split('=')[0].rsplit(':',1)[-1]))
    if GRADLE_VERIFY not in files:
        issues.append(f'{GRADLE_VERIFY} missing')
    else:
        try:
            xml = ET.fromstring(files[GRADLE_VERIFY]); comps = xml.findall('.//{*}component')
            verified = 0
            for comp in comps:
                for artifact in comp.findall('.//{*}artifact'):
                    for sha in artifact.findall('{*}sha256'):
                        check(bool(HEX.fullmatch(str(sha.get('value','')))) and sha.get('value') != '0'*64,
                              f'Gradle invalid SHA-256: {comp.get("name")}')
                        verified += 1
            check(bool(comps) and verified > 0, 'Gradle verification metadata lacks checked artifact digests')
        except ET.ParseError as error:
            issues.append(f'Gradle verification XML invalid: {error}')

    # Pinned production images must include ALL dependencies, not only Dockerfile FROM.
    docker = files['deploy/production/Dockerfile'].decode()
    compose = files['compose.production.yaml'].decode()
    base = re.findall(r'^FROM\s+(\S+)', docker, re.M)
    services = re.findall(r'^\s+image:\s+(\S+)', compose, re.M)
    remote = [x for x in base + services if not x.startswith('cajeerlogs')]
    check(len(base) >= 2, 'Production Dockerfile has no base images')
    check(any(x.startswith('postgres:17@') for x in remote), 'PostgreSQL production image not pinned')
    check(any(x.startswith('clickhouse/clickhouse-server:25.8@') for x in remote), 'ClickHouse production image not pinned')
    for ref in remote:
        match = REF.fullmatch(ref)
        check(bool(match), f'Unpinned/invalid production OCI digest: {ref}')
        if match:
            dep('oci', match['image'], ref, 'production')
            resolved.append(dict(ecosystem='oci', name=match['image'], digest=match['digest']))

    return {'schema': 2, 'declarations': sorted(declarations, key=lambda x: (x['ecosystem'], x['name'])),
            'resolved': sorted(resolved, key=lambda x: (x['ecosystem'], x['name'])),
            'unresolved': sorted(set(issues)), 'locked': not issues,
            'scope': 'committed lock graphs and OCI references; installation requires independent package-manager and registry checks'}


def files_from_checkout(root: Path) -> dict[str, bytes]:
    names = ['composer.json', 'composer.lock', 'packages/ts-sdk/package.json',
             'packages/ts-sdk/package-lock.json', 'clients/python/pyproject.toml',
             'clients/java-kotlin/build.gradle.kts', GRADLE_LOCK, GRADLE_VERIFY,
             'deploy/production/Dockerfile', 'compose.production.yaml']
    return {name: (root/name).read_bytes() for name in names if (root/name).is_file()}


def command(argv: list[str], cwd: Path, timeout: int = 900, capture: bool = False) -> bytes:
    result = subprocess.run(argv, cwd=cwd, check=True, timeout=timeout,
                            stdout=subprocess.PIPE if capture else None)
    return result.stdout or b''


def tool(name: str) -> str:
    result = shutil.which(name)
    if not result:
        raise RuntimeError(f'Required package manager not installed: {name}')
    return result


def validate_install(root: Path, docker: bool = True) -> None:
    """Verify downloads by package managers, then validate registry manifest digests."""
    composer, npm, gradle = (tool(x) for x in ('composer', 'npm', 'gradle'))
    command([composer, 'validate', '--strict', '--no-interaction'], root)
    command([composer, 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--no-scripts'], root)
    command([composer, 'audit', '--locked', '--no-interaction'], root)
    ts = root/'packages/ts-sdk'
    command([npm, 'ci', '--ignore-scripts', '--no-fund', '--no-audit'], ts)
    command([npm, 'run', 'check'], ts)
    command([npm, 'audit', '--audit-level=high'], ts)
    kotlin = root/'clients/java-kotlin'
    # First resolve against repositories, then prove cached verification is sufficient
    # for an independent offline rebuild. Both enforce SHA-256 metadata.
    command([gradle, '--no-daemon', '--dependency-verification=strict', 'build'], kotlin)
    command([gradle, '--no-daemon', '--offline', '--dependency-verification=strict', 'build'], kotlin)
    if docker:
        tool('docker')
        for item in inspection(files_from_checkout(root))['declarations']:
            if item['ecosystem'] != 'oci':
                continue
            ref = item['constraint']
            raw = command(['docker','buildx','imagetools','inspect','--raw',ref], root, capture=True)
            expected = ref.rsplit('@sha256:',1)[-1]
            if hashlib.sha256(raw).hexdigest() != expected:
                raise RuntimeError('OCI registry returned a different manifest: ' + ref)


def refresh(root: Path) -> None:
    """Operator-only command: use actual registries, never synthesize package data."""
    composer, npm, gradle = (tool(x) for x in ('composer', 'npm', 'gradle'))
    command([composer, 'update', '--no-interaction', '--prefer-dist', '--no-progress', '--no-scripts'], root)
    ts = root/'packages/ts-sdk'
    command([npm, 'install', '--package-lock-only', '--ignore-scripts', '--no-fund'], ts)
    kotlin = root/'clients/java-kotlin'
    command([gradle, '--no-daemon', '--write-locks', '--write-verification-metadata', 'sha256', 'build'], kotlin)
    issues = inspection(files_from_checkout(root))['unresolved']
    if issues:
        raise RuntimeError('Generated lockfiles incomplete: ' + '; '.join(issues))
    validate_install(root, docker=True)
    print('Resolved, installed and verified all pinned third-party dependencies')


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=('inspect', 'verify', 'refresh'))
    parser.add_argument('--root', type=Path, default=ROOT)
    parser.add_argument('--install', action='store_true', help='Execute real package managers, audits, builds and OCI registry checks')
    args = parser.parse_args()
    root = args.root.resolve()
    try:
        if args.operation == 'refresh':
            refresh(root)
            return 0
        evidence = inspection(files_from_checkout(root))
        print(json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True))
        if args.operation == 'verify':
            if evidence['unresolved']:
                raise RuntimeError('; '.join(evidence['unresolved']))
            if args.install:
                validate_install(root)
        return 0
    except (RuntimeError, ValueError, OSError, subprocess.SubprocessError, KeyError, ET.ParseError) as error:
        print(f'Dependency verification failed: {error}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

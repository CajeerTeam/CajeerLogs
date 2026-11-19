#!/usr/bin/env python3
"""Rebuild and audit the *real* release ZIP; emit verifiable, unsigned local metadata.

The in-toto statement here is NOT an authentication signature. GitHub Actions
signs the artifact digest separately with OIDC-backed build provenance.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import tomllib
import zipfile

import release
import dependency_locks


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def encoded(obj: object) -> bytes:
    return (json.dumps(obj, ensure_ascii=False, sort_keys=True, indent=2) + '\n').encode()


def package_evidence(files: dict[str, bytes]) -> dict:
    """Validate archived dependency graphs via the production lock verifier.

    A syntactically valid lock is not yet certified: the CI `dependency-integrity`
    job installs exact locked content, runs security audits, and fetches OCI
    manifests whose raw SHA-256 must match the references in the archive.
    """
    return dependency_locks.inspection(files)


def release_files(archive: Path) -> tuple[dict, dict[str, bytes]]:
    meta = release.verify(archive)
    with zipfile.ZipFile(archive) as z:
        return meta, {n: z.read(n) for n in meta['files']}


def sbom(archive: Path, meta: dict, files: dict[str, bytes], deps: dict) -> dict:
    digest = sha(archive.read_bytes())
    items = []
    for name, item in sorted(meta['files'].items()):
        items.append({'type': 'file', 'name': name, 'bom-ref': 'file:' + name,
                      'hashes': [{'alg': 'SHA-256', 'content': item['sha256']}],
                      'properties': [{'name': 'cajeer:bytes', 'value': str(item['bytes'])}]})
    for declaration in deps['declarations']:
        items.append({'type': 'library', 'name': declaration['name'],
                      'bom-ref': f"declared:{declaration['ecosystem']}:{declaration['name']}",
                      'properties': [
                          {'name': 'cajeer:ecosystem', 'value': declaration['ecosystem']},
                          {'name': 'cajeer:constraint', 'value': declaration['constraint']},
                          {'name': 'cajeer:resolution', 'value': 'declared-not-installed'}]})
    for dep in deps['resolved']:
        identity = dep.get('integrity') or dep.get('reference') or dep.get('digest')
        items.append({'type': 'library', 'name': dep['name'],
                      'version': dep.get('version') or None,
                      'bom-ref': f"locked:{dep['ecosystem']}:{dep['name']}",
                      'properties': [
                          {'name': 'cajeer:ecosystem', 'value': dep['ecosystem']},
                          {'name': 'cajeer:lock-reference', 'value': identity},
                          {'name': 'cajeer:resolution', 'value': 'locked-not-installed'}]})
    return {'bomFormat': 'CycloneDX', 'specVersion': '1.6', 'version': 1,
            'serialNumber': 'urn:uuid:' + __import__('uuid').uuid5(__import__('uuid').NAMESPACE_URL, digest).hex[:8] + '-'
                + __import__('uuid').uuid5(__import__('uuid').NAMESPACE_URL, digest).hex[8:12] + '-'
                + __import__('uuid').uuid5(__import__('uuid').NAMESPACE_URL, digest).hex[12:16] + '-'
                + __import__('uuid').uuid5(__import__('uuid').NAMESPACE_URL, digest).hex[16:20] + '-'
                + __import__('uuid').uuid5(__import__('uuid').NAMESPACE_URL, digest).hex[20:],
            'metadata': {'component': {'type': 'application', 'name': 'CajeerLogs', 'version': meta['version'],
                                       'bom-ref': 'cajeerlogs-release'},
                         'properties': [{'name': 'cajeer:archive-sha256', 'value': digest},
                                        {'name': 'cajeer:dependency-scope', 'value': deps['scope']}]},
            'components': items}


def statement(archive: Path, meta: dict, dependencies: dict) -> dict:
    return {'_type': 'https://in-toto.io/Statement/v1',
            'subject': [{'name': archive.name, 'digest': {'sha256': sha(archive.read_bytes())}}],
            'predicateType': 'https://slsa.dev/provenance/v1',
            'predicate': {'buildDefinition': {
                'buildType': 'https://cajeerlogs.example/build/release-zip/v1',
                'externalParameters': {'version': meta['version']},
                'internalParameters': {'zip_timestamp': '1980-01-01T00:00:00', 'compression': 'deflate-9'},
                'resolvedDependencies': [dict(uri=f'pkg:{d["ecosystem"]}/{d["name"]}', digest={'sha256': d['digest']}) for d in dependencies['resolved'] if d['ecosystem'] == 'oci']},
                'runDetails': {'builder': {'id': 'local-untrusted-build; see OIDC-signed CI attestation'},
                               'metadata': {'invocationId': 'not-attested'}}},
            'cajeer:dependencyComplete': dependencies['locked']}


def sidecars(archive: Path):
    meta, files = release_files(archive)
    deps = package_evidence(files)
    bom = sbom(archive, meta, files, deps)
    provenance = statement(archive, meta, deps)
    report = {'format': 1, 'scope': 'release-supply-chain', 'version': meta['version'],
              'archive_sha256': sha(archive.read_bytes()), 'dependency_lock_complete': deps['locked'],
              'dependency_issues': deps['unresolved'], 'sbom_sha256': sha(encoded(bom)),
              'provenance_sha256': sha(encoded(provenance)),
              'note': 'Lock graphs are checked structurally. CI must also install packages, audit them, verify OCI registries and authenticate GitHub OIDC attestations.'}
    return bom, provenance, report


def write(path: Path, content: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(encoded(content))


def compare(path: Path, content: dict) -> None:
    if path.read_bytes() != encoded(content):
        raise ValueError(f'Invalid/tampered supply-chain sidecar: {path.name}')


def reproduce(source: Path, archive: Path) -> dict:
    """Build twice with independent interpreters/source directories, no caches or network."""
    inputs = release.source_files(source)
    with tempfile.TemporaryDirectory(prefix='cajeer-rebuild-') as tmp:
        hashes = []
        for index in (1, 2):
            copy = Path(tmp) / f'isolated-{index}'
            copy.mkdir()
            for name, data in inputs.items():
                path = copy / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(data)
            result = Path(tmp) / f'built-{index}.zip'
            env = dict(os.environ, PYTHONDONTWRITEBYTECODE='1', PYTHONHASHSEED=str(index))
            subprocess.run([sys.executable, str(copy/'scripts/release.py'), 'build', str(result)],
                           cwd=copy, env=env, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
            release.verify(result)
            hashes.append(sha(result.read_bytes()))
    expected = sha(archive.read_bytes())
    if len(set(hashes + [expected])) != 1:
        raise ValueError(f'Nonreproducible: release and clean builds disagree')
    return {'format': 1, 'scope': 'reproducible-build', 'status': 'passed',
            'version': release.verify(archive)['version'], 'archive_sha256': expected,
            'independent_builds': [{'index': i, 'sha256': h} for i,h in enumerate(hashes,1)]}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    sp = ap.add_subparsers(dest='command', required=True)
    for name in ('generate', 'verify'):
        a = sp.add_parser(name)
        a.add_argument('archive', type=Path)
        a.add_argument('--sbom', type=Path, required=True)
        a.add_argument('--provenance', type=Path, required=True)
        a.add_argument('--report', type=Path, required=True)
        a.add_argument('--strict-dependencies', action='store_true')
    rp = sp.add_parser('reproduce')
    rp.add_argument('archive', type=Path)
    rp.add_argument('--source', type=Path, required=True)
    rp.add_argument('--report', type=Path, required=True)
    args = ap.parse_args()
    try:
        if args.command == 'reproduce':
            result = reproduce(args.source, args.archive)
            write(args.report, result)
        else:
            bom, provenance, result = sidecars(args.archive)
            if args.command == 'generate':
                write(args.sbom, bom); write(args.provenance, provenance); write(args.report, result)
            else:
                compare(args.sbom, bom); compare(args.provenance, provenance); compare(args.report, result)
            if args.strict_dependencies and not result['dependency_lock_complete']:
                raise ValueError('Dependency graph not locked: ' + '; '.join(result['dependency_issues']))
        print('Supply-chain', args.command, 'passed')
        return 0
    except (ValueError, OSError, KeyError, subprocess.SubprocessError, zipfile.BadZipFile) as error:
        print('Supply-chain failure:', error, file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())

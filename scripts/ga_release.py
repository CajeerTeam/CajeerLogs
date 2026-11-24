#!/usr/bin/env python3
"""Fail-closed CajeerLogs GA promotion: verify cryptographically signed GitHub provenance
for the EXACT certified ZIP and certification report before publishing GitHub Release.

The local SHA-256 is an integrity check, NOT a signature. `gh attestation verify`
performs Sigstore certificate/statement validation and enforces the expected
repository, tag, commit and signer workflow; errors block publication.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

import release

VERSION = '1.0.2'
TAG = 'v' + VERSION
WORKFLOW = '.github/workflows/runtime.yml'
NAME = f'CajeerLogs-{VERSION}.zip'
HEX = re.compile(r'[0-9a-f]{64}\Z')
SHA = re.compile(r'[0-9a-f]{40}\Z')
REPO = re.compile(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z')

class GAError(ValueError):
    pass

def sha(path: Path) -> str:
    with path.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()

def read_json(path: Path) -> dict:
    if not path.is_file() or path.stat().st_size > 25_000_000:
        raise GAError('Evidence is missing or oversized: ' + path.name)
    obj = json.loads(path.read_text(encoding='utf8'))
    if not isinstance(obj, dict):
        raise GAError('Evidence must be an object: ' + path.name)
    return obj

def gh(args: list[str], timeout=120) -> str:
    p = subprocess.run(['gh', *args], capture_output=True, text=True, timeout=timeout, check=False)
    if p.returncode != 0:
        raise GAError('Authenticated GitHub command failed: ' + ' '.join(args[:3]))
    return p.stdout

def verify_attestation(artifact: Path, repo: str, source_digest: str) -> None:
    # GitHub CLI checks signature, certificate chain and subject digest. The tag,
    # commit and signer constraints prevent accepting unrelated signed artifacts.
    args=['attestation','verify',str(artifact),'--repo',repo,
          '--source-ref','refs/tags/'+TAG,'--source-digest',source_digest,
          '--signer-workflow',repo+'/'+WORKFLOW,'--deny-self-hosted-runners',
          '--predicate-type','https://slsa.dev/provenance/v1','--format','json']
    result=json.loads(gh(args))
    if not isinstance(result,list) or not result:
        raise GAError('No verified signed attestations for '+artifact.name)

def evidence(archive: Path, certificate: Path, checksum: Path, sbom: Path,
             provenance: Path, supply_chain: Path, reproduction: Path, notes: Path) -> dict:
    if archive.name != NAME or not archive.is_file():
        raise GAError('GA artifact name or file is invalid')
    meta=release.verify(archive)
    if meta.get('version') != VERSION:
        raise GAError('Release ZIP is not 1.0.2')
    actual=sha(archive)
    if checksum.read_text(encoding='ascii').strip() != f'{actual}  {NAME}':
        raise GAError('Release checksum sidecar does not match ZIP')
    report=read_json(certificate)
    if (report.get('format')!=1 or report.get('status')!='passed'
        or report.get('scope')!='database-release-and-production-smoke'
        or report.get('version')!=VERSION or report.get('archive_sha256')!=actual):
        raise GAError('Final certification is missing, failed or for a different ZIP')
    if (not isinstance(report.get('stages'),list)
        or not any(s.get('name')=='production-and-throughput-certification'
                   and s.get('status')=='passed' and s.get('exit_code')==0
                   for s in report['stages'])):
        raise GAError('Real production/load stage not certified')
    for field in ('database_reports','admin_report','sdk_report','integration_stability_report',
                  'analytics_storage_report','security_report','performance_report'):
        data=report.get(field)
        if not data or (isinstance(data,dict) and data.get('status')!='passed'):
            raise GAError('Missing/failed certified stage: '+field)
    db=report['database_reports']
    if (not isinstance(db,list) or len(db)!=4
        or {(x.get('php_series'),x.get('postgres_major')) for x in db}
             != {(p,g) for p in ('8.4','8.5') for g in ('17','18')}
        or any(x.get('status')!='passed' or x.get('archive_sha256')!=actual for x in db)):
        raise GAError('Database compatibility matrix not fully certified')
    chain=read_json(supply_chain)
    if (chain.get('archive_sha256')!=actual or chain.get('dependency_lock_complete') is not True
        or chain.get('dependency_issues') != [] or chain.get('version')!=VERSION):
        raise GAError('Unresolved supply-chain dependencies')
    reproduction_data=read_json(reproduction)
    if (reproduction_data.get('archive_sha256')!=actual or reproduction_data.get('status')!='passed'
        or len(reproduction_data.get('independent_builds',[]))!=2
        or any(x.get('sha256')!=actual for x in reproduction_data['independent_builds'])):
        raise GAError('Independent builds not identical')
    if chain.get('sbom_sha256')!=sha(sbom) or chain.get('provenance_sha256')!=sha(provenance):
        raise GAError('SBOM/provenance mismatch')
    if not notes.is_file() or '1.0.2' not in notes.read_text(encoding='utf8'):
        raise GAError('GA notes missing')
    return {'version':VERSION,'zip_sha256':actual,'certification_sha256':sha(certificate),
            'matrix': ['PHP '+p+'/PostgreSQL '+g for p,g in sorted({(x['php_series'],x['postgres_major']) for x in db})]}

def main() -> int:
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument('command',choices=['verify','publish'])
    ap.add_argument('--archive',type=Path,required=True)
    ap.add_argument('--certificate',type=Path,required=True)
    ap.add_argument('--checksum',type=Path,required=True)
    ap.add_argument('--sbom',type=Path,required=True)
    ap.add_argument('--provenance',type=Path,required=True)
    ap.add_argument('--supply-chain',type=Path,required=True)
    ap.add_argument('--reproduction',type=Path,required=True)
    ap.add_argument('--notes',type=Path,required=True)
    ap.add_argument('--repo',required=True)
    ap.add_argument('--source-digest',required=True)
    args=ap.parse_args()
    try:
        if not REPO.fullmatch(args.repo) or not SHA.fullmatch(args.source_digest):
            raise GAError('A trusted repository and 40-character source SHA are required')
        if os.environ.get('GITHUB_REPOSITORY') != args.repo or os.environ.get('GITHUB_SHA') != args.source_digest or os.environ.get('GITHUB_REF') != 'refs/tags/'+TAG:
            raise GAError('GitHub tag/repository/commit context does not match release')
        result=evidence(args.archive,args.certificate,args.checksum,args.sbom,args.provenance,
                        args.supply_chain,args.reproduction,args.notes)
        verify_attestation(args.archive,args.repo,args.source_digest)
        verify_attestation(args.certificate,args.repo,args.source_digest)
        if args.command == 'publish':
            # Refuse idempotent-looking overwrite. Immutable GA releases are created once.
            existing=subprocess.run(['gh','release','view',TAG,'--repo',args.repo],
                                    capture_output=True,text=True,timeout=30,check=False)
            if existing.returncode == 0:
                raise GAError('GA release already exists; refusing overwrite')
            gh(['release','create',TAG,str(args.archive),str(args.checksum),str(args.sbom),
                str(args.provenance),str(args.supply_chain),str(args.reproduction),str(args.certificate),
                '--repo',args.repo,'--verify-tag','--title','CajeerLogs '+VERSION+' Production Stable GA',
                '--notes-file',str(args.notes),'--latest'],timeout=180)
        print(json.dumps(dict(result,authenticated_signature=True,published=args.command=='publish'),sort_keys=True))
        return 0
    except (GAError,ValueError,OSError,subprocess.SubprocessError) as exc:
        print('GA rejected: '+str(exc),file=sys.stderr)
        return 1

if __name__ == '__main__':
    sys.exit(main())

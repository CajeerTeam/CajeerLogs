"""Supply-chain baseline negative tests against actual source manifests and OCI pins."""
from __future__ import annotations
import json
import io
import contextlib
from unittest.mock import patch
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
import dependency_locks as locks


class DependencyLockTest(unittest.TestCase):
    def setUp(self):
        self.files = locks.files_from_checkout(ROOT)

    def test_production_oci_references_are_full_sha256(self):
        result = locks.inspection(self.files)
        pins = [d for d in result['declarations'] if d['ecosystem']=='oci']
        self.assertEqual(4, len(pins))
        self.assertTrue(all(locks.REF.fullmatch(x['constraint']) for x in pins))
        self.assertFalse(any('OCI' in issue for issue in result['unresolved']))

    def test_postgres_pin_must_be_amd64_capable_index(self):
        reference = 'postgres:17@sha256:' + 'b'*64
        valid = json.dumps({'manifests':[{'digest':'sha256:'+'a'*64,
            'platform':{'os':'linux','architecture':'amd64'}}]}).encode()
        locks.require_postgres_amd64_index(valid, reference)
        invalid = (
            b'not json',
            json.dumps({'schemaVersion':2,'mediaType':'application/vnd.oci.image.manifest.v1+json'}).encode(),
            json.dumps({'manifests':[{'digest':'sha256:'+'c'*64,
                'platform':{'os':'linux','architecture':'386'}}]}).encode(),
            json.dumps({'manifests':[{'digest':'not-a-sha256',
                'platform':{'os':'linux','architecture':'amd64'}}]}).encode(),
        )
        for raw in invalid:
            with self.subTest(raw=raw), self.assertRaises(RuntimeError):
                locks.require_postgres_amd64_index(raw, reference)

    def test_missing_lockfiles_are_fatal(self):
        files = dict(self.files)
        for name in ('composer.lock', 'packages/ts-sdk/package-lock.json', locks.GRADLE_LOCK, locks.GRADLE_VERIFY):
            files.pop(name, None)
        result = locks.inspection(files)
        self.assertTrue(all(any(path in error for error in result['unresolved'])
                            for path in ('composer.lock','package-lock.json','gradle.lockfile','verification-metadata.xml')))
        self.assertFalse(result['locked'])

    def test_composer_empty_lock_does_not_pass(self):
        files = dict(self.files)
        files['composer.lock'] = json.dumps({'content-hash': 'f'*32, 'packages': [], 'packages-dev': []}).encode()
        issues = locks.inspection(files)['unresolved']
        self.assertTrue(any('no resolved packages' in issue for issue in issues))
        self.assertTrue(any('root requirement missing' in issue for issue in issues))

    def test_composer_fabricated_reference_does_not_pass(self):
        files = dict(self.files)
        files['composer.lock'] = json.dumps({'content-hash':'f'*32, 'packages': [
            {'name': 'psr/log', 'version':'3.0.0', 'dist': {'reference': 'fabricated'}}], 'packages-dev':[]}).encode()
        self.assertTrue(any('not immutable' in x for x in locks.inspection(files)['unresolved']))

    def test_npm_empty_or_fake_sri_does_not_pass(self):
        files = dict(self.files)
        pkg = json.loads(files['packages/ts-sdk/package.json'])
        files['packages/ts-sdk/package-lock.json'] = json.dumps({'name':pkg['name'], 'version':pkg['version'],
            'lockfileVersion':3, 'packages': {'': { 'devDependencies':pkg['devDependencies'] },
                'node_modules/typescript': {'version':'5.8.3','resolved':'https://registry.npmjs.org/typescript/-/typescript-5.8.3.tgz','integrity':'fake'}}}).encode()
        issues = locks.inspection(files)['unresolved']
        self.assertTrue(any('missing pinned version/resolved/SRI' in issue for issue in issues))
        self.assertTrue(any('missing direct dependency' in issue for issue in issues))

    def test_unpinned_base_image_rejected(self):
        files = dict(self.files)
        files['deploy/production/Dockerfile'] = files['deploy/production/Dockerfile'].replace(
            b'php:8.4-fpm-trixie@sha256:1071b5609d16d60674f78eea8cf9bd7e9e79169ecb566d015c931c5ee789bb13',
            b'php:8.4-fpm-trixie')
        self.assertTrue(any('Unpinned/invalid production OCI digest' in e for e in locks.inspection(files)['unresolved']))

    def test_gradle_verification_metadata_rejects_zero(self):
        files = dict(self.files)
        files[locks.GRADLE_LOCK] = b'org.jetbrains.kotlin:kotlin-stdlib:2.0.0=compileClasspath\n'
        files[locks.GRADLE_VERIFY] = b'<verification-metadata><components><component name="kotlin-stdlib"><artifact><sha256 value="' + b'0'*64 + b'"/></artifact></component></components></verification-metadata>'
        self.assertTrue(any('Gradle invalid SHA-256' in e for e in locks.inspection(files)['unresolved']))

    def test_gradle_429_retries_with_strict_verification(self):
        command = ['gradle', '--no-daemon', '--dependency-verification=strict', 'build']
        throttled = b"Could not GET 'https://repo.maven.apache.org/maven2/example.pom'. Received status code 429 from server: Too Many Requests"
        results = [subprocess.CompletedProcess(command, 1, throttled),
                   subprocess.CompletedProcess(command, 0, b'BUILD SUCCESSFUL')]
        with patch.object(locks.subprocess, 'run', side_effect=results) as run, \
             patch.object(locks.time, 'sleep') as sleep, \
             contextlib.redirect_stdout(io.TextIOWrapper(io.BytesIO(), encoding='utf-8')):
            locks.gradle_with_registry_backoff(command, ROOT)
        self.assertEqual(2, run.call_count)
        sleep.assert_called_once_with(15)
        self.assertTrue(all(call.args[0] == command for call in run.call_args_list))

    def test_gradle_integrity_error_is_not_retried(self):
        command = ['gradle', '--dependency-verification=strict', 'build']
        integrity_error = b'Dependency verification failed: SHA-256 checksum does not match'
        with patch.object(locks.subprocess, 'run', return_value=subprocess.CompletedProcess(command, 1, integrity_error)) as run, \
             patch.object(locks.time, 'sleep') as sleep, \
             contextlib.redirect_stdout(io.TextIOWrapper(io.BytesIO(), encoding='utf-8')):
            with self.assertRaises(subprocess.CalledProcessError):
                locks.gradle_with_registry_backoff(command, ROOT)
        run.assert_called_once()
        sleep.assert_not_called()

    def test_gradle_429_exhaustion_fails_closed(self):
        command = ['gradle', '--dependency-verification=strict', 'build']
        throttled = b"Could not GET 'https://repo.maven.apache.org/maven2/example.pom'. Received status code 429 from server"
        with patch.object(locks.subprocess, 'run', return_value=subprocess.CompletedProcess(command, 1, throttled)) as run, \
             patch.object(locks.time, 'sleep') as sleep, \
             contextlib.redirect_stdout(io.TextIOWrapper(io.BytesIO(), encoding='utf-8')):
            with self.assertRaises(subprocess.CalledProcessError):
                locks.gradle_with_registry_backoff(command, ROOT)
        self.assertEqual(5, run.call_count)
        self.assertEqual([15, 30, 60, 90], [c.args[0] for c in sleep.call_args_list])

    def test_verify_fails_closed_when_locks_unavailable(self):
        result = subprocess.run([sys.executable, str(ROOT/'scripts/dependency_locks.py'), 'verify', '--root', str(ROOT)],
                                text=True, capture_output=True, timeout=15)
        if not locks.inspection(self.files)['locked']:
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('Dependency verification failed', result.stderr)
        else:
            self.assertEqual(result.returncode, 0, result.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=2)

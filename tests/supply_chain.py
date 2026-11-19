"""Exercise actual supply-chain tools: rebuilds, SBOM, provenance, integrity, fail-closed locks."""
import copy
import importlib.util
import hashlib
import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
import release
# unittest discovery imports this file as "supply_chain"; load the real script under a distinct name.
spec = importlib.util.spec_from_file_location('cajeer_supply_chain_tool', ROOT/'scripts/supply_chain.py')
sc = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sc)

class SupplyChainTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory(prefix='cajeer-supply-test-')
        cls.root = Path(cls.temp.name)
        cls.archive = cls.root / 'candidate.zip'
        release.build(ROOT, cls.archive)
        cls.meta, cls.files = sc.release_files(cls.archive)

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def test_rebuilds_are_isolated_and_byte_identical(self):
        r = sc.reproduce(ROOT, self.archive)
        self.assertEqual('passed', r['status'])
        self.assertEqual([sc.sha(self.archive.read_bytes())]*2,
                         [x['sha256'] for x in r['independent_builds']])

    def test_actual_file_sbom_and_hashes(self):
        bom, provenance, report = sc.sidecars(self.archive)
        self.assertEqual('CycloneDX', bom['bomFormat'])
        self.assertEqual('1.6', bom['specVersion'])
        actual = {x['name']: x['hashes'][0]['content'] for x in bom['components'] if x['type']=='file'}
        self.assertEqual({n: m['sha256'] for n,m in self.meta['files'].items()}, actual)
        self.assertEqual(sc.sha(self.archive.read_bytes()), report['archive_sha256'])
        self.assertEqual(report['archive_sha256'], provenance['subject'][0]['digest']['sha256'])
        self.assertEqual(bom, sc.sidecars(self.archive)[0])

    def test_unlocked_dependencies_are_explicit_not_fabricated(self):
        files = dict(self.files)
        for name in ('composer.lock', 'packages/ts-sdk/package-lock.json', sc.dependency_locks.GRADLE_LOCK, sc.dependency_locks.GRADLE_VERIFY):
            files.pop(name, None)
        deps = sc.package_evidence(files)
        self.assertFalse(deps['locked'])
        self.assertTrue(any('composer.lock' in issue for issue in deps['unresolved']))
        self.assertTrue(any('package-lock.json' in issue for issue in deps['unresolved']))
        self.assertTrue(any('gradle.lockfile' in issue for issue in deps['unresolved']))
        self.assertTrue(any(d['ecosystem']=='oci' and '@sha256:' in d['constraint'] for d in deps['declarations']))
        self.assertTrue(any(d['name']=='typescript' and d['constraint']=='5.8.3' for d in deps['declarations']))

    def test_tamper_and_missing_sidecar_rejected(self):
        bom, prov, report = sc.sidecars(self.archive)
        path = self.root / 'sbom.json'
        sc.write(path, bom)
        sc.compare(path, bom)
        altered = copy.deepcopy(bom)
        altered['components'][0]['hashes'][0]['content'] = '0'*64
        sc.write(path, altered)
        with self.assertRaises(ValueError): sc.compare(path, bom)
        path.unlink()
        with self.assertRaises(OSError): sc.compare(path, bom)
        self.assertEqual(report['dependency_lock_complete'], sc.package_evidence(self.files)['locked'])

    def test_dependency_manifest_fails_on_missing_constraints(self):
        files=dict(self.files)
        npm=json.loads(files['packages/ts-sdk/package.json'])
        npm['devDependencies']['typescript']='*'
        files['packages/ts-sdk/package.json']=sc.encoded(npm)
        deps=sc.package_evidence(files)
        self.assertTrue(any(d['name']=='typescript' and d['constraint']=='*' for d in deps['declarations']))

    def test_reproduce_detects_source_drift(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)/'repo'
            root.mkdir()
            for name, data in release.source_files(ROOT).items():
                p=root/name; p.parent.mkdir(parents=True,exist_ok=True); p.write_bytes(data)
            (root/'README.md').write_text('modified documentation')
            with self.assertRaises(ValueError): sc.reproduce(root,self.archive)

if __name__ == '__main__':
    unittest.main(verbosity=2)

#!/usr/bin/env python3
"""Execute positive and negative checks on the actual shipped stable bundle."""
import pathlib
import shutil
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
import release
import stable_release


class StableReleaseTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='cajeer-release-identity-test-')
        cls.archive = pathlib.Path(cls.tmp.name) / 'release.zip'
        release.build(ROOT, cls.archive)
        cls.target = pathlib.Path(cls.tmp.name) / 'extracted'
        release.verify(cls.archive, cls.target)

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def test_real_release_cli_and_platform(self):
        result = stable_release.verify(self.archive)
        self.assertEqual(result['version'], '1.0.7')
        self.assertGreaterEqual(result['versioned_browser_references'], 20)
        self.assertEqual(result['checks'][-1], 'production-security')

    def test_mixed_sdk_rejected(self):
        path = self.target / 'packages/ts-sdk/package.json'
        original = path.read_bytes()
        try:
            path.write_bytes(original.replace(b'1.0.7', b'0.8.9'))
            with self.assertRaisesRegex(stable_release.InvalidRelease, 'TypeScript SDK'):
                stable_release.identity(self.target)
        finally:
            path.write_bytes(original)

    def test_stale_cache_busting_rejected(self):
        path = self.target / 'public/admin/assets/panel.js'
        original = path.read_bytes()
        try:
            path.write_bytes(original.replace(b'?v=1.0.7', b'?v=0.8.9', 1))
            with self.assertRaisesRegex(stable_release.InvalidRelease, 'Old browser cache'):
                stable_release.identity(self.target)
        finally:
            path.write_bytes(original)

    def test_insecure_production_configuration_rejected(self):
        path = self.target / 'compose.production.yaml'
        original = path.read_bytes()
        try:
            path.write_bytes(original.replace(b"APP_DEBUG: 'false'", b"APP_DEBUG: 'true'"))
            with self.assertRaisesRegex(stable_release.InvalidRelease, 'production security'):
                stable_release.identity(self.target)
        finally:
            path.write_bytes(original)

    def test_legacy_image_rejected(self):
        path = self.target / 'compose.production.yaml'
        original = path.read_bytes()
        try:
            path.write_bytes(original.replace(b'cajeerlogs:1.0.7', b'cajeerlogs:0.7.5'))
            with self.assertRaisesRegex(stable_release.InvalidRelease, 'Production image'):
                stable_release.identity(self.target)
        finally:
            path.write_bytes(original)

    def test_signed_zip_manifest_rejects_tamper(self):
        from zipfile import ZipFile, ZIP_DEFLATED
        bad = pathlib.Path(self.tmp.name) / 'tampered.zip'
        with ZipFile(self.archive) as original, ZipFile(bad, 'w', ZIP_DEFLATED) as edited:
            for item in original.infolist():
                data = original.read(item.filename)
                if item.filename == 'release.json':
                    data = data.replace(b'1.0.7', b'0.8.9')
                edited.writestr(item, data)
        with self.assertRaises(ValueError):
            stable_release.verify(bad)


if __name__ == '__main__':
    unittest.main(verbosity=2)

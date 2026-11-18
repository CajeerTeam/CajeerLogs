#!/usr/bin/env python3
"""Exercise the actual repeat-runner: leaks, timeouts and contaminated env fail closed."""
import os
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'scripts'))
from integration_stability import clean_environment, run_command


class StabilizationTests(unittest.TestCase):
    def test_no_database_credentials_leak_into_http_fixtures(self):
        with tempfile.TemporaryDirectory() as temp:
            original = os.environ.copy()
            try:
                os.environ.update(DB_HOST='prod.internal', DB_PASSWORD='sensitive',
                                  AWS_SECRET_ACCESS_KEY='secret', PGHOST='prod-db', CAJEER_TEST_DATABASE='1')
                env = clean_environment(Path(temp))
            finally:
                os.environ.clear()
                os.environ.update(original)
            for name in ('DB_HOST', 'DB_PASSWORD', 'AWS_SECRET_ACCESS_KEY', 'PGHOST'):
                self.assertNotIn(name, env)
            self.assertEqual(env['CAJEER_TEST_DATABASE'], '0')
            self.assertEqual(env['APP_ENV'], 'test')

    def test_nonzero_is_not_success(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            code, seconds, digest = run_command((sys.executable, '-c', 'raise SystemExit(5)'),
                                                root, os.environ.copy(), root/'test.log', 30)
            self.assertEqual(code, 5)
            self.assertGreater(seconds, 0)
            self.assertEqual(len(digest), 64)

    def test_timeout_kills_child_process_group(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            code, _, _ = run_command((sys.executable, '-c', 'import time;time.sleep(60)'),
                                     root, os.environ.copy(), root/'timeout.log', 1)
            self.assertEqual(code, 124)

    def test_success_has_real_log_digest(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            code, _, digest = run_command((sys.executable, '-c', 'print("real output")'),
                                          root, os.environ.copy(), root/'success.log', 30)
            self.assertEqual(code, 0)
            self.assertEqual(digest, __import__('hashlib').sha256(b'real output\n').hexdigest())


if __name__ == '__main__':
    unittest.main()

"""Executable integration tests for the live HTTP load generator and fault proxy.

The HTTP fixture is only an integration test of the runner. Certification is
issued exclusively by running the same code against a real disposable deployment.
"""
import importlib.util
import json
from pathlib import Path
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer

ROOT = Path(__file__).resolve().parents[1]
import sys
sys.path.insert(0, str(ROOT / 'scripts'))
import load_test

spec = importlib.util.spec_from_file_location('benchmark_fixture', ROOT / 'tests/benchmark.py')
fixture_mod = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = fixture_mod
spec.loader.exec_module(fixture_mod)


class LoadTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = ThreadingHTTPServer(('127.0.0.1', 0), fixture_mod.Fixture)
        cls.worker = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.worker.start()
        cls.origin = f'http://127.0.0.1:{cls.server.server_port}'

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.worker.join()

    def args(self, scenario='all'):
        a = load_test.parser().parse_args(['--url', self.origin, '--project', fixture_mod.PROJECT,
            '--source', 'app', '--scenario', scenario, '--rate', '20', '--batch', '2',
            '--concurrency', '4', '--async-percent', '25', '--sustained-seconds', '1',
            '--stress-seconds', '1.5', '--soak-seconds', '1', '--fault-seconds', '1.5',
            '--min-rate-ratio', '.75', '--p95-ms', '5000', '--fault-p95-ms', '5000',
            '--drain-seconds', '30', '--confirm-disposable'])
        return a

    def test_all_real_requests_verified_and_faults(self):
        import os
        saved = os.environ.get('CAJEER_TOKEN')
        os.environ['CAJEER_TOKEN'] = fixture_mod.TOKEN
        try:
            with tempfile.TemporaryDirectory() as d:
                args = self.args()
                args.output = str(Path(d)/'result.json')
                result = load_test.run(args)
                self.assertEqual(result['status'], 'passed', result)
                self.assertFalse(result['certified'])
                self.assertEqual([p['scenario'] for p in result['phases']], list(load_test.PHASES))
                for phase in result['phases']:
                    self.assertGreater(phase['durable_events'], 0)
                    self.assertEqual(phase['durable_events'], phase['verified_events'])
                    self.assertTrue(all(phase['gates'].values()), phase)
                fault = result['phases'][-1]
                self.assertGreater(fault['fault_injection']['lost_ack'], 0)
                self.assertGreater(fault['retry_attempts'], 0)
                self.assertGreater(fault['idempotent_replays'], 0)
                persisted = json.loads(Path(args.output).read_text())
                self.assertEqual(persisted['status'], 'passed')
        finally:
            if saved is None:
                os.environ.pop('CAJEER_TOKEN', None)
            else:
                os.environ['CAJEER_TOKEN'] = saved

    def test_queue_only_async_and_certification_threshold(self):
        import os
        saved = os.environ.get('CAJEER_TOKEN')
        os.environ['CAJEER_TOKEN'] = fixture_mod.TOKEN
        try:
            args = self.args('sustained')
            args.async_percent = 100
            args.batch = 10
            result = load_test.run(args)
            self.assertEqual(result['status'], 'passed', result)
            phase = result['phases'][0]
            self.assertEqual(phase['completed_requests'], phase['async_jobs'])
            self.assertEqual(phase['durable_events'], phase['verified_events'])
            args.url = 'https://example.test'
            args.certify = True
            args.archive = '/invalid/path.zip'
            args.scenario = 'all'
            args.sustained_seconds, args.stress_seconds, args.soak_seconds, args.fault_seconds = 60, 120, 900, 60
            args.async_percent = 20
            with self.assertRaisesRegex(ValueError, '100% async'):
                load_test.validate_args(args)
        finally:
            if saved is None: os.environ.pop('CAJEER_TOKEN', None)
            else: os.environ['CAJEER_TOKEN'] = saved

    def test_cli_opt_in_and_live_json_output(self):
        import os
        import subprocess
        command = [sys.executable, str(ROOT / 'scripts/load_test.py'),
                   '--url', self.origin, '--project', fixture_mod.PROJECT, '--source', 'app',
                   '--scenario', 'sustained', '--rate', '4', '--batch', '2',
                   '--sustained-seconds', '1', '--drain-seconds', '30']
        with tempfile.TemporaryDirectory() as d:
            report = str(Path(d)/'out.json')
            denied = subprocess.run(command + ['--output', report],
                env=dict(os.environ, CAJEER_TOKEN=fixture_mod.TOKEN), capture_output=True, text=True)
            self.assertNotEqual(denied.returncode, 0)
            self.assertFalse(Path(report).exists())
            allowed = subprocess.run(command + ['--output', report, '--confirm-disposable'],
                env=dict(os.environ, CAJEER_TOKEN=fixture_mod.TOKEN, CAJEER_LOAD_TEST='1'),
                capture_output=True, text=True)
            self.assertEqual(allowed.returncode, 0, allowed.stderr)
            self.assertEqual(json.loads(Path(report).read_text())['status'], 'passed')

    def test_database_corruption_fails_gate(self):
        client = load_test.Client(self.origin, fixture_mod.TOKEN, fixture_mod.PROJECT, 'app')
        fixture_mod.Fixture.corrupt = True
        try:
            result = load_test.run_phase(client, 'sustained', self.args('sustained'))
            self.assertEqual(result['status'], 'failed')
            self.assertFalse(result['gates']['durable_consistency'])
        finally:
            fixture_mod.Fixture.corrupt = False

    def test_percentiles_are_bounded(self):
        hist = load_test.Histogram()
        self.assertEqual(hist.summarize(), {'count': 0})
        for i in range(100000):
            hist.add((i % 10 + 1) / 1000)
        self.assertEqual(hist.summarize()['p95_ms'], 10)
        self.assertEqual(len(hist.buckets), 10)

    def test_refuse_unsafe_and_false_certification(self):
        args = self.args()
        args.url = 'http://not-localhost.example'
        with self.assertRaisesRegex(ValueError, 'HTTPS'):
            load_test.validate_args(args)
        args.url = self.origin
        args.certify = True
        with self.assertRaisesRegex(ValueError, 'Certification requires verified HTTPS'):
            load_test.validate_args(args)
        args.certify = False
        args.rate = 2500
        with self.assertRaises(ValueError):
            load_test.validate_args(args)

    def test_redirect_is_not_followed_with_token(self):
        self.assertIsNone(load_test.NoRedirect().redirect_request(None, None, 307, '', {}, 'https://elsewhere.invalid'))


if __name__ == '__main__':
    unittest.main()

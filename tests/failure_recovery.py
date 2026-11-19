#!/usr/bin/env python3
"""Orchestration safety tests; live stop/start is separately mandatory on disposable CI services."""
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

script = Path(__file__).resolve().parents[1] / 'scripts' / 'failure_recovery.py'
spec = importlib.util.spec_from_file_location('cajeer_failure_recovery_runner', script)
fr = importlib.util.module_from_spec(spec)
spec.loader.exec_module(fr)


class FailureRecoverySafety(unittest.TestCase):
    def setUp(self):
        self.env = dict(GITHUB_ACTIONS='true', GITHUB_JOB='clickhouse-integration', APP_ENV='test', CAJEER_TEST_DATABASE='1', CAJEER_TEST_CLICKHOUSE='1',
                        CAJEER_TEST_SERVICE_STOP='1', DB_HOST='127.0.0.1', DB_DATABASE='testdb',
                        DB_USERNAME='tester', DB_PASSWORD='password', CLICKHOUSE_DSN='http://127.0.0.1:8123',
                        CLICKHOUSE_DATABASE='testdb', CLICKHOUSE_USERNAME='tester',
                        CLICKHOUSE_PASSWORD='password', CAJEER_TEST_POSTGRES_CONTAINER='a'*64,
                        CAJEER_TEST_CLICKHOUSE_CONTAINER='b'*64)

    def test_requires_explicit_disposable_opt_in(self):
        with self.assertRaises(ValueError):
            fr.preflight(dict(self.env, APP_ENV='production'))
        with self.assertRaises(ValueError):
            fr.preflight(dict(self.env, CAJEER_TEST_SERVICE_STOP='0'))
        with self.assertRaises(ValueError):
            fr.preflight(dict(self.env, DB_DATABASE='postgres'))
        with self.assertRaises(ValueError):
            fr.preflight(dict(self.env, GITHUB_ACTIONS='false'))
        with self.assertRaises(ValueError):
            fr.preflight(dict(self.env, DB_HOST='prod.internal'))
        with self.assertRaises(ValueError):
            fr.preflight(dict(self.env, CLICKHOUSE_DSN='https://external.example'))

    def test_requires_exact_independent_container_ids(self):
        for value in ('postgres', 'a'*12, 'a'*63+'G', 'b'*64):
            env = dict(self.env, CAJEER_TEST_POSTGRES_CONTAINER=value)
            with self.assertRaises(ValueError):
                fr.preflight(env)
        self.assertEqual(fr.preflight(self.env)['postgres'], 'a'*64)

    def test_refuses_wrong_image_and_nonrunning_service(self):
        with patch.object(fr, 'docker', return_value='a'*64 + '|postgres:16|true'):
            with self.assertRaises(ValueError):
                fr.check_service('a'*64, 'postgres:17')
        with patch.object(fr, 'docker', return_value='a'*64 + '|postgres:17|false'):
            with self.assertRaises(ValueError):
                fr.check_service('a'*64, 'postgres:17')

    def test_restarts_stopped_container_even_when_assertion_fails(self):
        calls=[]
        def docker(*arguments, **_):
            calls.append(arguments)
            if arguments[0]=='inspect':
                return 'true' if any(c[0]=='start' for c in calls) else 'false'
            return ''
        with patch.object(fr, 'docker', side_effect=docker):
            with self.assertRaisesRegex(RuntimeError, 'deliberate'):
                with fr.stopped_service('a'*64):
                    raise RuntimeError('deliberate failure during stopped service')
        self.assertIn(('stop','--time','5','a'*64), calls)
        self.assertIn(('start','a'*64), calls)

    def test_stop_timeout_still_restores_disposable_database(self):
        calls=[]
        def docker(*arguments, **_):
            calls.append(arguments)
            if arguments[0]=='stop':
                raise fr.subprocess.TimeoutExpired(['docker','stop'], 25)
            if arguments[0]=='inspect':
                return 'true' if any(c[0]=='start' for c in calls) else 'false'
            return ''
        with patch.object(fr, 'docker', side_effect=docker):
            with self.assertRaises(fr.subprocess.TimeoutExpired):
                with fr.stopped_service('a'*64):
                    pass
        self.assertIn(('start','a'*64), calls)

    def test_real_php_phase_must_emit_assertion(self):
        with tempfile.TemporaryDirectory() as directory:
            with patch.object(fr.subprocess,'run') as run:
                run.return_value.returncode=0
                run.return_value.stdout='test exited silently\n'
                with self.assertRaises(RuntimeError):
                    fr.phase(Path(directory), 'verify', Path(directory)/'state.json', self.env)


if __name__ == '__main__':
    unittest.main()

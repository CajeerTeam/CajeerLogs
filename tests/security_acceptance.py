"""Security gate regressions: execute packaged tests and reject incomplete evidence."""
import copy
import json
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
import certify
import release
import security_acceptance as security


class SecurityAcceptanceTest(unittest.TestCase):
    def evidence(self):
        return {'format': 1, 'scope': 'security-acceptance', 'status': 'passed',
                'version': '0.9.2', 'archive_sha256': 'a' * 64,
                'stages': [{'name': name, 'status': 'passed', 'exit_code': 0,
                            'seconds': .01, 'assertions': minimum,
                            'log_sha256': 'b'*64}
                           for name, _, minimum in security.STAGES]}

    def test_real_archive_executed(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            archive = folder / 'candidate.zip'
            release.build(ROOT, archive)
            path = folder / 'evidence.json'
            self.assertTrue(security.run(archive, path), path.read_text())
            report = json.loads(path.read_text())
            self.assertEqual(report['status'], 'passed')
            info = {'version': '0.9.2', 'archive_sha256': report['archive_sha256']}
            certify.validate_security(report, info)

    def test_reject_tampering_and_omitted_executions(self):
        info = {'version': '0.9.2', 'archive_sha256': 'a'*64}
        certify.validate_security(self.evidence(), info)
        for kind in ('missing_stage','duplicate','skip','nonzero','too_few','wrong_hash',
                     'wrong_version','wrong_scope','wrong_log_hash','no_time','nan_time'):
            value = copy.deepcopy(self.evidence())
            if kind == 'missing_stage': value['stages'].pop()
            elif kind == 'duplicate': value['stages'][1] = copy.deepcopy(value['stages'][0])
            elif kind == 'skip': value['stages'][2]['status'] = 'skipped'
            elif kind == 'nonzero': value['stages'][2]['exit_code'] = 1
            elif kind == 'too_few': value['stages'][2]['assertions'] = 0
            elif kind == 'wrong_hash': value['archive_sha256'] = 'c'*64
            elif kind == 'wrong_version': value['version'] = '0.8.8'
            elif kind == 'wrong_scope': value['scope'] = 'unit'
            elif kind == 'wrong_log_hash': value['stages'][1]['log_sha256'] = 'invalid'
            elif kind == 'no_time': value['stages'][1]['seconds'] = 0
            elif kind == 'nan_time': value['stages'][1]['seconds'] = float('nan')
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                certify.validate_security(value, info)

    def test_reject_wrong_candidate_and_failed_report_persists(self):
        with tempfile.TemporaryDirectory() as directory:
            folder=Path(directory)
            archive = folder / 'release.zip'
            # Actual release content, mutated version but still validly packaged.
            release.build(ROOT, archive)
            with self.assertRaises(FileNotFoundError):
                security.run_stage(['nonexistent-binary'], ROOT, {}, 15)
            path = folder / 'report.json'
            self.assertFalse(security.run(archive, path, timeout=1))
            self.assertEqual(json.loads(path.read_text())['status'], 'failed')


if __name__ == '__main__':
    unittest.main()

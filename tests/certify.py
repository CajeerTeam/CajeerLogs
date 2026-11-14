"""Local tests of gate validation; synthetic reports are never certification evidence."""
import copy
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import certify
import release

class GateTest(unittest.TestCase):
    def setUp(self):
        self.info={'version':'0.2.0','archive_sha256':'a'*64}
        self.reports=[dict(self.info, format=1, scope='database-core', status='passed', php_series=php, postgres_major=pg, php=php+'.1',postgres=pg+'.1',stages=[{'name':name,'status':'passed','exit_code':0} for name in certify.STAGES]) for php,pg in sorted(certify.MATRIX)]

    def test_full_matrix(self): certify.validate_matrix(self.reports,self.info)
    def test_missing_matrix(self):
        with self.assertRaises(ValueError): certify.validate_matrix(self.reports[:-1],self.info)
    def test_wrong_hash(self):
        self.reports[0]['archive_sha256']='b'*64
        with self.assertRaises(ValueError): certify.validate_matrix(self.reports,self.info)
    def test_missing_stage(self):
        self.reports[0]['stages'].pop()
        with self.assertRaises(ValueError): certify.validate_matrix(self.reports,self.info)
    def test_nonzero_exit(self):
        self.reports[0]['stages'][0]['exit_code']=1
        with self.assertRaises(ValueError): certify.validate_matrix(self.reports,self.info)
    def test_duplicate_matrix(self):
        self.reports[1]=copy.deepcopy(self.reports[0])
        with self.assertRaises(ValueError): certify.validate_matrix(self.reports,self.info)
    def test_failed_report(self):
        self.reports[0]['status']='failed'
        with self.assertRaises(ValueError): certify.validate_matrix(self.reports,self.info)
    def test_missing_opt_in_writes_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            tmp=Path(temp); archive=tmp/'release.zip'; release.build(ROOT,archive)
            out=tmp/'evidence.json'; env=dict(os.environ,CAJEER_TEST_DATABASE='0')
            result=subprocess.run([sys.executable,str(ROOT/'scripts/certify.py'),'database',str(archive),'--from-010',str(tmp),'--from-019',str(tmp),'--output',str(out)],env=env,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(json.loads(out.read_text())['status'],'failed')
            self.assertEqual(json.loads(out.read_text())['stages'],[])

if __name__=='__main__': unittest.main()

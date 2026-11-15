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
        self.info={'version':'0.4.2','archive_sha256':'a'*64}
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
    def admin_evidence(self):
        return dict(self.info,format=1,scope='admin-panel',status='passed',php_series='8.4',postgres_major='17',php='8.4.1',postgres='17.1',stages=[{'name':name,'status':'passed','exit_code':0} for name in certify.ADMIN_STAGES])

    def test_admin_full(self): certify.validate_admin(self.admin_evidence(),self.info)
    def test_admin_missing_browser(self):
        r=self.admin_evidence(); r['stages'].pop()
        with self.assertRaises(ValueError): certify.validate_admin(r,self.info)
    def test_admin_wrong_archive(self):
        r=self.admin_evidence(); r['archive_sha256']='b'*64
        with self.assertRaises(ValueError): certify.validate_admin(r,self.info)
    def test_admin_failed_browser(self):
        r=self.admin_evidence(); r['stages'][-1]['exit_code']=1
        with self.assertRaises(ValueError): certify.validate_admin(r,self.info)
    def test_admin_wrong_runtime(self):
        r=self.admin_evidence(); r['postgres_major']='18'
        with self.assertRaises(ValueError): certify.validate_admin(r,self.info)
    def test_admin_offline_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            tmp=Path(temp); archive=tmp/'release.zip'; release.build(ROOT,archive)
            out=tmp/'admin.json'; env=dict(os.environ,CAJEER_BROWSER_OFFLINE='1')
            result=subprocess.run([sys.executable,str(ROOT/'scripts/certify.py'),'admin',str(archive),'--output',str(out)],env=env,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            evidence=json.loads(out.read_text());self.assertEqual(evidence['status'],'failed');self.assertEqual(evidence['stages'],[])
    def test_missing_opt_in_writes_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            tmp=Path(temp); archive=tmp/'release.zip'; release.build(ROOT,archive)
            out=tmp/'evidence.json'; env=dict(os.environ,CAJEER_TEST_DATABASE='0')
            result=subprocess.run([sys.executable,str(ROOT/'scripts/certify.py'),'database',str(archive),'--from-010',str(tmp),'--from-020',str(tmp),'--from-029',str(tmp),'--from-030',str(tmp),'--from-032',str(tmp),'--from-033',str(tmp),'--from-034',str(tmp),'--from-035',str(tmp),'--from-036',str(tmp),'--from-037',str(tmp),'--from-038',str(tmp),'--from-039',str(tmp),'--from-040',str(tmp),'--from-041',str(tmp),'--output',str(out)],env=env,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(json.loads(out.read_text())['status'],'failed')
            self.assertEqual(json.loads(out.read_text())['stages'],[])

if __name__=='__main__': unittest.main()

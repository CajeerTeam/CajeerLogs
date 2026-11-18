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
        self.info={'version':'0.7.6','archive_sha256':'a'*64}
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

    def sdk_evidence(self):
        from integrations import STAGES
        return dict(self.info,format=1,scope='sdk-integrations',status='passed',
                    stages=[{'name':name,'status':'passed','exit_code':0} for name in STAGES])

    def test_sdk_packaged_matrix(self):
        certify.validate_sdk(self.sdk_evidence(),self.info)

    def test_sdk_missing_step(self):
        r=self.sdk_evidence(); r['stages'].pop()
        with self.assertRaises(ValueError):certify.validate_sdk(r,self.info)

    def test_sdk_wrong_release(self):
        r=self.sdk_evidence();r['archive_sha256']='f'*64
        with self.assertRaises(ValueError):certify.validate_sdk(r,self.info)

    def test_sdk_nonzero_step(self):
        r=self.sdk_evidence();r['stages'][3]['exit_code']=1
        with self.assertRaises(ValueError):certify.validate_sdk(r,self.info)

    def performance_evidence(self):
        base={'completion_accounting':True,'achieved_rate':True,'durable_consistency':True,
              'error_rate':True,'p95_latency':True,'offered_rate':True,'run_duration':True,
              'per_step_offered_rate':True,'minute_p95_latency':True,'concurrent_search':True}
        phases=[]
        for name,duration in zip(('sustained','stress','soak','fault'),(60,120,900,60)):
            gates=dict(base)
            if name=='fault':gates.update(faults_exercised=True,ambiguous_commit_recovered=True)
            phases.append({'scenario':name,'marker':'perf-test-'+name,
                           'status':'passed','duration_target_seconds':duration,
                           'admission_seconds':duration,'async_jobs':100,'durable_events':200,
                           'verified_events':200,'scheduled_requests':10,'completed_requests':10,
                           'throughput_requests_per_second':10,'effective_ingest_rps':10,
                           'latency':{'count':10,'p95_ms':5},
                           'step_schedule':[{'target_rps':10,'seconds':duration,'scheduled':10}]* (3 if name=='stress' else 1),
                           'minute_latency':[{'count':10,'p95_ms':5}],
                           'concurrent_search_latency':{'count':10,'p95_ms':5},
                           'concurrent_search_errors':{},'errors':{},'gates':gates,
                           'retry_attempts':5,'idempotent_replays':1,
                           'fault_injection':{'pre503':1,'pre429':1,'lost_ack':1,'delay':1,'upstream_success':1}})
        return dict(self.info,format=1,scope='load-performance',status='passed',certified=True,
                    certification_requested=True,readiness_confirmed=True,phases=phases)

    def test_full_live_performance_evidence(self):
        certify.validate_performance(self.performance_evidence(),self.info)

    def test_short_or_unverified_performance_cannot_certify(self):
        for mutation in ('short','sync','hash','fault','gate','uncertified','missing_metrics','missing_gate','unverified_requests','read_failure','duplicate_marker'):
            r=self.performance_evidence()
            if mutation=='short': r['phases'][2]['duration_target_seconds']=3
            if mutation=='sync': r['phases'][1]['async_jobs']=0
            if mutation=='hash': r['archive_sha256']='b'*64
            if mutation=='fault': r['phases'][3]['fault_injection']['lost_ack']=0
            if mutation=='gate': r['phases'][1]['gates']['durable_consistency']=False
            if mutation=='uncertified': r['certified']=False
            if mutation=='missing_metrics': del r['phases'][0]['effective_ingest_rps']
            if mutation=='missing_gate': del r['phases'][0]['gates']['achieved_rate']
            if mutation=='unverified_requests': r['phases'][1]['completed_requests']=0
            if mutation=='read_failure': r['phases'][2]['concurrent_search_errors']={'failed':1}
            if mutation=='duplicate_marker': r['phases'][1]['marker']=r['phases'][0]['marker']
            with self.assertRaises(ValueError): certify.validate_performance(r,self.info)

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
            result=subprocess.run([sys.executable,str(ROOT/'scripts/certify.py'),'database',str(archive),'--from-010',str(tmp),'--from-020',str(tmp),'--from-029',str(tmp),'--from-030',str(tmp),'--from-032',str(tmp),'--from-033',str(tmp),'--from-034',str(tmp),'--from-035',str(tmp),'--from-036',str(tmp),'--from-037',str(tmp),'--from-038',str(tmp),'--from-039',str(tmp),'--from-040',str(tmp),'--from-041',str(tmp),'--from-042',str(tmp),'--from-043',str(tmp),'--from-044',str(tmp),'--from-045',str(tmp),'--from-046',str(tmp),'--from-047',str(tmp),'--from-048',str(tmp),'--from-049',str(tmp),'--from-0410',str(tmp),'--from-050',str(tmp),'--from-051',str(tmp),'--from-052',str(tmp),'--from-053',str(tmp),'--from-054',str(tmp),'--from-055',str(tmp),'--from-056',str(tmp),'--from-057',str(tmp),'--from-058',str(tmp),'--from-059',str(tmp),'--from-0510',str(tmp),'--from-060',str(tmp),'--from-061',str(tmp),'--from-062',str(tmp),'--from-063',str(tmp),'--from-064',str(tmp),'--from-065',str(tmp),'--from-066',str(tmp),'--from-067',str(tmp),'--from-068',str(tmp),'--from-069',str(tmp),'--output',str(out)],env=env,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(json.loads(out.read_text())['status'],'failed')
            self.assertEqual(json.loads(out.read_text())['stages'],[])

if __name__=='__main__': unittest.main()

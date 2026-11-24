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
        self.info={'version':'1.0.9','archive_sha256':'a'*64}
        proofs={name.removeprefix('upgrade-'):{'format':1,'scope':'upgrade-rollback','status':'passed',
                'from_version':name.removeprefix('upgrade-'),'to_version':'1.0.9','archive_sha256':'a'*64,
                'backup_sha256':'b'*64,'pending_migrations':[], 'checks':list(certify.UPGRADE_CHECKS)}
                for name in certify.STAGES if name.startswith('upgrade-')}
        self.reports=[dict(self.info, upgrade_rollback=copy.deepcopy(proofs), format=1, scope='database-core', status='passed', php_series=php, postgres_major=pg, php=php+'.1',postgres=pg+'.1',stages=[{'name':name,'status':'passed','exit_code':0} for name in certify.STAGES], disaster_recovery={'format':1,'scope':'disaster-recovery','status':'passed','version':'1.0.9','archive_sha256':'a'*64,'backup_sha256':'b'*64,'source_version':'1.0.9','fault_domain_separation_proven':False,'snapshot_rows':33,'restored_rows':33,'snapshot_checksum':'e'*32,'restored_checksum':'e'*32,'rpo_upper_bound_seconds':1,'recovery_readiness_seconds':2.1,'postgres_major':pg,'checks':list(certify.DR_CHECKS)}, migration_scale={'version':'1.0.9','archive_sha256':'a'*64,'status':'passed','test_rows':100000,'backfill_checksum':'5000050000','checks':24,'postgresql_version':pg+'.1','independent_replay':{'status':'verified','schema':'cl_cert_test','migrations':36,'history_sha256':'c'*64,'catalog_sha256':'d'*64,'reference_sha256':'d'*64,'duration_ms':100,'scratch_schema_persisted':False}}) for php,pg in sorted(certify.MATRIX)]

    def test_supply_chain_blocks_unlocked_or_mismatched_builds(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'release.zip'
            release.build(ROOT,path)
            _, _, evidence=__import__('supply_chain').sidecars(path)
            correct={'format':1,'scope':'reproducible-build','status':'passed',
                     'version':'1.0.9','archive_sha256':evidence['archive_sha256'],
                     'independent_builds':[{'index':i,'sha256':evidence['archive_sha256']} for i in (1,2)]}
            info={'version':'1.0.9','archive_sha256':evidence['archive_sha256']}
            # The committed locks are now genuine. Create a separate unlocked
            # distribution so the fail-closed check remains meaningful both
            # before and after real dependency locks are checked in.
            source=Path(tmp)/'unlocked-source'
            missing={'composer.lock','packages/ts-sdk/package-lock.json',
                     'clients/java-kotlin/gradle.lockfile',
                     'clients/java-kotlin/gradle/verification-metadata.xml'}
            for name, data in release.source_files(ROOT).items():
                if name in missing:
                    continue
                target=source/name
                target.parent.mkdir(parents=True,exist_ok=True)
                target.write_bytes(data)
            unlocked_path=Path(tmp)/'unlocked.zip'
            release.build(source,unlocked_path)
            _, _, unlocked_report=__import__('supply_chain').sidecars(unlocked_path)
            unlocked_sha=unlocked_report['archive_sha256']
            unlocked_reproduction=dict(correct,archive_sha256=unlocked_sha,
                 independent_builds=[{'index':i,'sha256':unlocked_sha} for i in (1,2)])
            with self.assertRaisesRegex(ValueError,'Unresolved dependency'):
                certify.validate_supply_chain_evidence(unlocked_path,unlocked_report,unlocked_reproduction,
                    {'version':'1.0.9','archive_sha256':unlocked_sha})
            mismatched=dict(evidence,dependency_lock_complete=not evidence['dependency_lock_complete'])
            with self.assertRaisesRegex(ValueError,'differs'):
                certify.validate_supply_chain_evidence(path,mismatched,correct,info)

    def test_full_matrix(self): certify.validate_matrix(self.reports,self.info)
    def test_missing_large_migration_evidence(self):
        for kind in ('missing','wrong_hash','short_table','wrong_checksum','too_few_checks','wrong_pg'):
            reports=copy.deepcopy(self.reports)
            evidence=reports[0]['migration_scale']
            if kind=='missing': del reports[0]['migration_scale']
            elif kind=='wrong_hash': evidence['archive_sha256']='b'*64
            elif kind=='short_table': evidence['test_rows']=99
            elif kind=='wrong_checksum': evidence['backfill_checksum']='0'
            elif kind=='too_few_checks': evidence['checks']=1
            elif kind=='wrong_pg': evidence['postgresql_version']='19.1'
            with self.subTest(kind=kind),self.assertRaises(ValueError):
                certify.validate_matrix(reports,self.info)

    def test_independent_schema_replay_required(self):
        for kind in ('missing','wrong_count','wrong_catalog','leaked_schema','missing_history','invalid_digest'):
            reports=copy.deepcopy(self.reports)
            scale=reports[0]['migration_scale']
            replay=scale['independent_replay']
            if kind=='missing': del scale['independent_replay']
            elif kind=='wrong_count': replay['migrations']=32
            elif kind=='wrong_catalog': replay['reference_sha256']='a'*64
            elif kind=='leaked_schema': replay['scratch_schema_persisted']=True
            elif kind=='missing_history': del replay['history_sha256']
            elif kind=='invalid_digest': replay['catalog_sha256']='garbage'
            with self.subTest(kind=kind),self.assertRaisesRegex(ValueError,'independent PostgreSQL schema replay'):
                certify.validate_matrix(reports,self.info)

    def test_missing_rollback_proof(self):
        missing=copy.deepcopy(self.reports); del missing[0]['upgrade_rollback']['0.8.5']
        with self.assertRaisesRegex(ValueError,'Incomplete upgrade/rollback'):
            certify.validate_matrix(missing,self.info)

    def test_fake_rollback_archive_rejected(self):
        wrong=copy.deepcopy(self.reports)
        wrong[0]['upgrade_rollback']['0.8.5']['archive_sha256']='0'*64
        with self.assertRaisesRegex(ValueError,'Invalid actual upgrade/rollback'):
            certify.validate_matrix(wrong,self.info)

    def test_missing_rollback_runtime_check_rejected(self):
        wrong=copy.deepcopy(self.reports)
        wrong[0]['upgrade_rollback']['0.8.5']['checks'].remove('previous_binary_restarted')
        with self.assertRaisesRegex(ValueError,'Invalid actual upgrade/rollback'):
            certify.validate_matrix(wrong,self.info)

    def test_missing_dr_recovery_evidence(self):
        for kind in ('missing','wrong_archive','missing_fault','unbounded_rto','falsified_fault_domain','bad_data_checksum','missing_rows'):
            bad=copy.deepcopy(self.reports)
            proof=bad[0]['disaster_recovery']
            if kind=='missing': del bad[0]['disaster_recovery']
            elif kind=='wrong_archive': proof['archive_sha256']='e'*64
            elif kind=='missing_fault': proof['checks'].remove('corruption_rejected')
            elif kind=='unbounded_rto': proof['recovery_readiness_seconds']=301
            elif kind=='bad_data_checksum': proof['restored_checksum']='f'*32
            elif kind=='missing_rows': proof['restored_rows']=2
            else: proof['fault_domain_separation_proven']=True
            with self.subTest(kind=kind),self.assertRaises(ValueError):
                certify.validate_matrix(bad,self.info)

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

    def test_stage_timeout_is_fatal_and_recorded(self):
        with tempfile.TemporaryDirectory() as temp:
            report={'stages':[]}
            with self.assertRaises(RuntimeError):
                certify.run_stage(report,'intentional-timeout',
                                  [sys.executable,'-c','import time;time.sleep(30)'],
                                  Path(temp),os.environ.copy(),timeout=.15)
            self.assertEqual(report['stages'][0]['status'],'failed')
            self.assertEqual(report['stages'][0]['exit_code'],124)
            self.assertGreater(report['stages'][0]['seconds'],0)

    def stability_evidence(self):
        from integration_stability import STAGES
        return dict(self.info, format=1, scope='integration-stability', status='passed',
                    passes=2, stages=[{'pass':run,'name':name,'status':'passed','exit_code':0,
                                      'seconds':0.01,'log_sha256':'a'*64}
                                     for run in (1,2) for name,_ in STAGES])

    def test_stability_complete(self):
        certify.validate_stability(self.stability_evidence(), self.info)

    def test_stability_requires_both_real_passes(self):
        for mutation in ('missing', 'wrong_hash', 'wrong_version', 'skipped', 'nonzero',
                         'wrong_stage', 'wrong_passes', 'wrong_digest', 'empty_elapsed'):
            r=self.stability_evidence()
            if mutation=='missing': r['stages'].pop()
            elif mutation=='wrong_hash': r['archive_sha256']='b'*64
            elif mutation=='wrong_version': r['version']='0.8.0'
            elif mutation=='skipped': r['stages'][2]['status']='skipped'
            elif mutation=='nonzero': r['stages'][2]['exit_code']=124
            elif mutation=='wrong_stage': r['stages'][2]['name']='other'
            elif mutation=='wrong_passes': r['passes']=1
            elif mutation=='wrong_digest': r['stages'][2]['log_sha256']='bad'
            elif mutation=='empty_elapsed': r['stages'][2]['seconds']=0
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                certify.validate_stability(r, self.info)

    def performance_evidence(self):
        base={'completion_accounting':True,'achieved_rate':True,'durable_consistency':True,'persisted_payload':True,
              'error_rate':True,'p95_latency':True,'offered_rate':True,'run_duration':True,
              'per_step_offered_rate':True,'minute_p95_latency':True,'concurrent_search':True}
        phases=[]
        for name,duration in zip(('sustained','stress','soak','fault'),(60,120,900,60)):
            gates=dict(base)
            if name=='fault':gates.update(faults_exercised=True,ambiguous_commit_recovered=True)
            plan=([4.,8.,16.] if name=='stress' else [4.])
            segment=duration/len(plan)
            schedule=[{'target_rps':rate,'seconds':segment,'scheduled':int(rate*segment)} for rate in plan]
            planned=sum(step['scheduled'] for step in schedule)
            phases.append({'scenario':name,'marker':'perf-test-'+name,
                           'status':'passed','duration_target_seconds':duration,
                           'admission_seconds':duration,'async_jobs':100,'durable_events':200,
                           'verified_events':200,'scheduled_requests':planned,'completed_requests':planned,
                           'throughput_requests_per_second':10,'effective_ingest_rps':10,
                           'latency':{'count':10,'p95_ms':5},
                           'requested_rate_per_second':plan,'step_schedule':schedule,'error_rate':0.0,
                           'minute_latency':[{'count':10,'p95_ms':5}],
                           'concurrent_search_latency':{'count':10,'p95_ms':5},
                           'concurrent_search_errors':{},'errors':{},'gates':gates,
                           'retry_attempts':5,'idempotent_replays':1,
                           'fault_injection':{'pre503':1,'pre429':1,'lost_ack':1,'delay':1,'upstream_success':1}})
        return dict(self.info,format=1,scope='load-performance',status='passed',certified=True,
                    certification_requested=True,readiness_confirmed=True,
                    operations_acceptance={'version':'1.0.9','archive_sha256':'a'*64,
                        'checks':{key:True for key in ('production_security_audit','healthy_triage',
                            'private_incident_evidence','worker_outage_triaged',
                            'prometheus_worker_alert','postgres_outage_alert',
                            'post_recovery_triage','post_recovery_ingest')}},
                    thresholds={'p95_ms':5000,'fault_p95_ms':8000,'max_error_rate':.01,'fault_error_rate':.01,'min_rate_ratio':.85},phases=phases)

    def test_full_live_performance_evidence(self):
        certify.validate_performance(self.performance_evidence(),self.info)

    def test_short_or_unverified_performance_cannot_certify(self):
        for mutation in ('short','sync','hash','fault','gate','uncertified','missing_metrics','missing_gate','unverified_requests','read_failure','duplicate_marker','weak_slo','missing_ops','ops_failed','missing_payload_gate','nan_latency','tiny_load','wrong_steps','bad_error_rate'):
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
            if mutation=='missing_ops': del r['operations_acceptance']
            if mutation=='ops_failed': r['operations_acceptance']['checks']['post_recovery_ingest']=False
            if mutation=='missing_payload_gate': del r['phases'][0]['gates']['persisted_payload']
            if mutation=='weak_slo': r['thresholds']['p95_ms']=999999
            if mutation=='nan_latency': r['phases'][0]['latency']['p95_ms']=float('nan')
            if mutation=='tiny_load': r['phases'][2]['step_schedule'][0]['scheduled']=1
            if mutation=='wrong_steps': r['phases'][1]['step_schedule'][1]['target_rps']=1
            if mutation=='bad_error_rate': r['phases'][0]['error_rate']=.5
            with self.assertRaises(ValueError): certify.validate_performance(r,self.info)

    def storage_evidence(self):
        return dict(self.info,format=1,scope='analytics-storage-e2e',status='passed',
                    runtime={'php':'8.4.12','postgres':'170010','clickhouse':'25.8.3'},
                    stages=[{'name':name,'status':'passed','exit_code':0,'checks':15 if name=='service-stop-and-recovery' else 10,'seconds':0.5,
                             **({'recovery':{'postgres_stopped':True,'clickhouse_stopped':True,
                                  'worker_crash_recovered':True,'replay_verified':True,
                                  'data_reconciled':True,'isolated_projects':2}} if name=='service-stop-and-recovery' else {}),
                             **({'performance':{'events':300,'queries':12,
                                 'ingest_events_per_second':100.,'replication_events_per_second':100.,
                                 'stage_events_per_second':100.,'cold_events_per_second':100.,
                                 'restore_events_per_second':100.,'aggregate_p95_ms':30.,
                                 'min_ingest_events_per_second':10.,'min_replication_events_per_second':10.,
                                 'max_aggregate_p95_ms':5000.}} if name=='storage-fault-recovery' else {})}
                            for name,_ in certify.ANALYTICS_STORAGE_STAGES])

    def test_analytics_storage_evidence(self):
        certify.validate_analytics_storage(self.storage_evidence(),self.info)

    def test_analytics_storage_missing_or_tampered_rejected(self):
        for variant in ('missing','digest','version','failed','no_checks','bad_exit','wrong_runtime','reordered','missing_performance','failed_throughput','bad_latency','no_restart','bad_recovery'):
            report=self.storage_evidence()
            if variant=='missing': report['stages'].pop()
            elif variant=='digest': report['archive_sha256']='0'*64
            elif variant=='version': report['version']='0.7.8'
            elif variant=='failed': report['status']='failed'
            elif variant=='no_checks': report['stages'][0]['checks']=0
            elif variant=='bad_exit': report['stages'][1]['exit_code']=1
            elif variant=='wrong_runtime': report['runtime']['postgres']='160000'
            elif variant=='reordered': report['stages'].reverse()
            elif variant=='missing_performance': del report['stages'][-1]['performance']
            elif variant=='failed_throughput': report['stages'][-1]['performance']['ingest_events_per_second']=1.
            elif variant=='bad_latency': report['stages'][-1]['performance']['aggregate_p95_ms']=6000.
            elif variant=='no_restart': del report['stages'][-2]['recovery']
            elif variant=='bad_recovery': report['stages'][-2]['recovery']['clickhouse_stopped']=False
            with self.subTest(variant=variant),self.assertRaises(ValueError):
                certify.validate_analytics_storage(report,self.info)

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
            result=subprocess.run([sys.executable,str(ROOT/'scripts/certify.py'),'database',str(archive),'--from-010',str(tmp),'--from-020',str(tmp),'--from-029',str(tmp),'--from-030',str(tmp),'--from-032',str(tmp),'--from-033',str(tmp),'--from-034',str(tmp),'--from-035',str(tmp),'--from-036',str(tmp),'--from-037',str(tmp),'--from-038',str(tmp),'--from-039',str(tmp),'--from-040',str(tmp),'--from-041',str(tmp),'--from-042',str(tmp),'--from-043',str(tmp),'--from-044',str(tmp),'--from-045',str(tmp),'--from-046',str(tmp),'--from-047',str(tmp),'--from-048',str(tmp),'--from-049',str(tmp),'--from-0410',str(tmp),'--from-050',str(tmp),'--from-051',str(tmp),'--from-052',str(tmp),'--from-053',str(tmp),'--from-054',str(tmp),'--from-055',str(tmp),'--from-056',str(tmp),'--from-057',str(tmp),'--from-058',str(tmp),'--from-059',str(tmp),'--from-0510',str(tmp),'--from-060',str(tmp),'--from-061',str(tmp),'--from-062',str(tmp),'--from-063',str(tmp),'--from-064',str(tmp),'--from-065',str(tmp),'--from-066',str(tmp),'--from-067',str(tmp),'--from-068',str(tmp),'--from-069',str(tmp),'--from-079',str(tmp),'--from-080',str(tmp),'--from-081',str(tmp),'--from-082',str(tmp),'--from-083',str(tmp),'--from-084',str(tmp),'--from-085',str(tmp),'--from-086',str(tmp),'--from-087',str(tmp),'--from-088',str(tmp),'--from-089',str(tmp),'--from-090',str(tmp),'--from-091',str(tmp),'--from-092',str(tmp),'--from-093',str(tmp),'--from-094',str(tmp),'--from-095',str(tmp),'--from-096',str(tmp),'--from-097',str(tmp),'--from-098',str(tmp),'--from-099',str(tmp),'--output',str(out)],env=env,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(json.loads(out.read_text())['status'],'failed')
            self.assertEqual(json.loads(out.read_text())['stages'],[])

if __name__=='__main__': unittest.main()

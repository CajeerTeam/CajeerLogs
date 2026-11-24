#!/usr/bin/env python3
"""Security regression of GA promotion checks using synthetic evidence only."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import ga_release
import release

class GATests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp=tempfile.TemporaryDirectory()
        cls.root=Path(cls.tmp.name)
        cls.archive=cls.root/ga_release.NAME
        release.build(ROOT,cls.archive)
        cls.digest=ga_release.sha(cls.archive)
        cls.checksum=cls.archive.with_name(cls.archive.name+'.sha256')
        cls.cert=cls.root/'database-certification.json'
        cls.sbom=cls.root/'sbom.json';cls.sbom.write_text('{}')
        cls.prov=cls.root/'provenance.json';cls.prov.write_text('{}')
        cls.chain=cls.root/'chain.json';cls.repro=cls.root/'repro.json';cls.notes=cls.root/'notes.md'
        cls.notes.write_text('# GA 1.0.6')
        cert={'format':1,'status':'passed','scope':'database-release-and-production-smoke',
              'version':'1.0.6','archive_sha256':cls.digest,
              'stages':[{'name':'production-and-throughput-certification','status':'passed','exit_code':0}],
              'database_reports':[{'php_series':p,'postgres_major':g,'status':'passed','archive_sha256':cls.digest}
                   for p in ('8.4','8.5') for g in ('17','18')]}
        for name in ('admin_report','sdk_report','integration_stability_report','analytics_storage_report','security_report','performance_report'):
            cert[name]={'status':'passed'}
        cls.valid_cert=cert
        cls.valid_chain={'version':'1.0.6','archive_sha256':cls.digest,'dependency_lock_complete':True,
                        'dependency_issues':[],'sbom_sha256':ga_release.sha(cls.sbom),'provenance_sha256':ga_release.sha(cls.prov)}
        cls.valid_repro={'status':'passed','archive_sha256':cls.digest,'independent_builds':[{'sha256':cls.digest}]*2}

    @classmethod
    def tearDownClass(cls):cls.tmp.cleanup()

    def verify(self,cert=None,chain=None,repro=None):
        self.cert.write_text(json.dumps(cert if cert is not None else self.valid_cert))
        self.chain.write_text(json.dumps(chain if chain is not None else self.valid_chain))
        self.repro.write_text(json.dumps(repro if repro is not None else self.valid_repro))
        return ga_release.evidence(self.archive,self.cert,self.checksum,self.sbom,self.prov,self.chain,self.repro,self.notes)

    def test_valid_synthetic_evidence_is_structurally_accepted(self):
        self.assertEqual(self.verify()['version'],'1.0.6')
    def test_failed_certificate_rejected(self):
        data=dict(self.valid_cert,status='failed')
        with self.assertRaises(ga_release.GAError):self.verify(cert=data)
    def test_missing_live_load_rejected(self):
        data=dict(self.valid_cert,stages=[])
        with self.assertRaises(ga_release.GAError):self.verify(cert=data)
    def test_incomplete_matrix_rejected(self):
        data=dict(self.valid_cert,database_reports=self.valid_cert['database_reports'][:-1])
        with self.assertRaises(ga_release.GAError):self.verify(cert=data)
    def test_wrong_database_sha_rejected(self):
        data=dict(self.valid_cert,database_reports=[dict(x) for x in self.valid_cert['database_reports']])
        data['database_reports'][0]['archive_sha256']='0'*64
        with self.assertRaises(ga_release.GAError):self.verify(cert=data)
    def test_supply_chain_not_locked_rejected(self):
        data=dict(self.valid_chain,dependency_lock_complete=False)
        with self.assertRaises(ga_release.GAError):self.verify(chain=data)
    def test_reproduction_mismatch_rejected(self):
        data=dict(self.valid_repro,independent_builds=[{'sha256':'0'*64}]*2)
        with self.assertRaises(ga_release.GAError):self.verify(repro=data)
    def test_checksum_tamper_rejected(self):
        original=self.checksum.read_bytes()
        try:
            self.checksum.write_text('0'*64+'  '+self.archive.name+'\n')
            with self.assertRaises(ga_release.GAError):self.verify()
        finally:self.checksum.write_bytes(original)
    def test_attestation_missing_rejected(self):
        with patch.object(ga_release,'gh',return_value='[]'):
            with self.assertRaises(ga_release.GAError):
                ga_release.verify_attestation(self.archive,'Example/CajeerLogs','a'*40)
    def test_attestation_pins_repo_tag_commit_and_workflow(self):
        with patch.object(ga_release,'gh',return_value='[{"verified":true}]') as fake:
            ga_release.verify_attestation(self.archive,'Example/CajeerLogs','a'*40)
            command=fake.call_args.args[0]
            for constraint in ('--repo','Example/CajeerLogs','--source-ref','refs/tags/v1.0.6',
                               '--source-digest','a'*40,'--signer-workflow',
                               'Example/CajeerLogs/.github/workflows/runtime.yml','--deny-self-hosted-runners'):
                self.assertIn(constraint,command)

if __name__=='__main__':unittest.main(verbosity=2)

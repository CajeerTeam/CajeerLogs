#!/usr/bin/env python3
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from datetime import datetime, timezone
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
from automatic_update import Manager, DEFAULT, policy, in_window, UpdateError

class Host:
    def __init__(self): self.calls=[]; self.candidate='1.1.1'; self.fail=False; self.bad_proof=False
    def cli(self,root,*args,**kwargs):
        self.calls.append(args)
        if self.fail: raise UpdateError('network failed')
        if args[0]=='update:check': return {'status':'ok','channel':args[-1].split('=')[1],'update_available':self.candidate is not None,'candidate':self.candidate}
        if args[0]=='update:download': return {'status':'verified','version':args[-1].split('=')[1],'provenance':'invalid' if self.bad_proof else 'github_oidc_sigstore','sha256':'a'*64}
        raise AssertionError(args)

class Installer:
    def __init__(self,root,host): self.host=host
    def lock(self): return os.open('/dev/null',os.O_RDONLY)
    def read(self): return getattr(self.host,'journal',None)
    def install(self,version,db,units):
        self.host.calls.append(('install',version,db,tuple(units)))
        if getattr(self.host,'install_fail',False): raise UpdateError('failed')
        self.host.candidate=None
        return {'phase':'complete','to':version}

class Tests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name)/'current';(self.root/'storage/cache').mkdir(parents=True)
        self.now=int(datetime(2026,11,24,2,0,tzinfo=timezone.utc).timestamp());self.host=Host()
        self.manager=Manager(self.root,self.host,Installer,lambda:self.now);self.manager.directory.mkdir(mode=0o700)
    def configure(self,**overrides): return self.manager.configure(DEFAULT|overrides)
    def install_policy(self,**overrides): return self.configure(**({'mode':'install','units':['app.service','queue.service'],'databases':['next_a','next_b']}|overrides))
    def test_disabled_no_network(self):
        self.assertEqual(self.manager.tick()['status'],'off');self.assertEqual(self.host.calls,[])
    def test_interval_and_preview(self):
        self.configure(mode='check',channel='preview',interval_minutes=5)
        self.host.candidate='1.1.1-rc.1';s=self.manager.tick();self.assertEqual(s['candidate'],'1.1.1-rc.1');self.assertEqual(s['notices'][-1]['kind'],'available')
        self.now+=299;self.manager.tick();self.assertEqual(len(self.host.calls),1)
        self.now+=1;self.manager.tick();self.assertEqual(len(self.host.calls),2);self.assertEqual(len(self.manager.state()['notices']),1)
    def test_download_pinned_no_install(self):
        self.configure(mode='download');s=self.manager.tick();self.assertEqual(s['downloaded'],'1.1.1');self.assertEqual(self.host.calls[-1],('update:download','--channel=stable','--version=1.1.1'))
        self.now+=3600;self.manager.tick();self.assertEqual(sum(x[0]=='update:download' for x in self.host.calls),1)
    def test_untrusted_proof_no_install(self):
        self.install_policy(window_start='02:00');self.host.bad_proof=True
        self.assertEqual(self.manager.tick()['status'],'check_or_download_failed');self.assertFalse(any(x[0]=='install' for x in self.host.calls))
    def test_schedule_fresh_check_then_install(self):
        self.install_policy(interval_minutes=120);s=self.manager.tick();self.assertEqual(s['status'],'downloaded')
        self.now+=3600;s=self.manager.tick();self.assertEqual(s['status'],'installed');self.assertEqual(s['remaining_databases'],1);self.assertEqual(self.host.calls[-2][0],'update:check');self.assertEqual(self.host.calls[-1][2],'next_a')
        self.assertEqual(json.loads((self.root/'storage/cache/update-automation.json').read_text())['status'],'installed')
    def test_withdrawn_release_not_installed(self):
        self.install_policy();self.manager.tick();self.host.candidate=None;self.now+=3600
        self.assertEqual(self.manager.tick()['status'],'up_to_date');self.assertFalse(any(x[0]=='install' for x in self.host.calls))
    def test_failed_install_blocks_and_consumes_db(self):
        self.install_policy(window_start='02:00');self.host.install_fail=True;s=self.manager.tick();self.assertTrue(s['blocked']);self.assertEqual(s['remaining_databases'],1)
        self.now+=60;calls=len(self.host.calls);self.manager.tick();self.assertEqual(len(self.host.calls),calls)
        self.host.journal={'phase':'recovery_required'}
        with self.assertRaises(UpdateError):self.manager.resume()
        self.host.journal={'phase':'rolled_back'};self.host.install_fail=False;self.manager.resume();s=self.manager.tick();self.assertEqual(s['status'],'installed');self.assertEqual(self.host.calls[-1][2],'next_b')
    def test_interrupted_install_fails_closed(self):
        self.install_policy();s=self.manager.state();s['status']='installing';s['candidate']='1.1.1';self.manager.save(self.manager.settings(),s)
        self.assertEqual(self.manager.tick()['status'],'interrupted');self.assertEqual(self.host.calls,[])
    def test_empty_pool_and_recovery(self):
        self.install_policy(window_start='02:00',databases=['next_a']);s=self.manager.state();s['used_databases']=['next_a'];self.manager.save(self.manager.settings(),s)
        self.assertEqual(self.manager.tick()['status'],'database_pool_exhausted')
    def test_network_failure_backoff(self):
        self.install_policy(window_start='02:00');self.host.fail=True;self.manager.tick();self.now+=60;self.manager.tick();self.assertEqual(len(self.host.calls),1)
    def test_validate_policy_and_midnight(self):
        for x in [{'mode':'install','channel':'preview','units':['a.service'],'databases':['a']},{'mode':'install'},{'interval_minutes':0},{'window_start':'25:00'},{'units':['cajeerlogs-auto-update.timer']},{'databases':['postgres']},{'units':['a;rm.service']}]:
            with self.subTest(x=x),self.assertRaises(UpdateError):policy(DEFAULT|x)
        p=DEFAULT|{'window_start':'23:50','window_minutes':30};self.assertTrue(in_window(p,int(datetime(2026,11,24,0,10,tzinfo=timezone.utc).timestamp())));self.assertFalse(in_window(p,self.now))
    def test_operator_journal_not_web_projection(self):
        self.configure(mode='check');(self.root/'storage/cache/update-automation.json').write_text('{"mode":"install"}')
        self.assertEqual(self.manager.tick()['mode'],'check');self.assertFalse(any(x[0]=='install' for x in self.host.calls))

if __name__=='__main__':unittest.main(verbosity=2)

#!/usr/bin/env python3
"""Exercise the native cutover with real filesystem swaps and injected service/DB boundaries."""
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
from safe_update import Installer, UpdateError, change_database, copy_runtime, exchange, inode, version_key

class FixtureHost:
    def __init__(self,fail=None):
        self.fail=fail; self.stopped=False;self.calls=[];self.committed_writes=False
    def units(self,names):return names[:]
    def ready(self,root):
        self.calls.append(('ready',root.name))
        if self.fail=='stage_health' and root.name=='release':raise UpdateError('health failed')
        if self.fail=='switched_health' and root.name=='current' and (root/'version').read_text()=='1.0.5':raise UpdateError('switched health failed')
    def http(self,url,version):
        self.calls.append(('http',version))
        if self.fail=='post_commit' and version=='1.0.5':raise UpdateError('live check failed')
    def prepare(self,root,stage,target):
        self.calls.append(('verify',target))
        if self.fail=='provenance':raise UpdateError('unsigned')
        (stage/'version').write_text(target);(stage/'storage').mkdir()
        return 'a'*64
    def stop(self,units):self.stopped=True;self.calls.append(('stop',tuple(units)))
    def start(self,units,active):self.stopped=False;self.calls.append(('start',tuple(active)))
    def assert_services(self,active):
        if self.stopped: raise UpdateError('services are stopped')
    def cli(self,root,*args,**kwargs):
        self.calls.append((args[0],root.name))
        if args[0]=='inspect':return {'version':'1.0.4','database':'old_db','schema':'public','url':'https://logs.example.test'}
        if not self.stopped:raise AssertionError('database operations ran with live services')
        if args[0]==self.fail:raise UpdateError('injected failure')
        if args[0]=='snapshot':return {'version':'1.0.4','id':'backup-20261124T010000Z-'+'a'*16,'sha256':'b'*64}
        if args[0]=='clone':return {'status':'cloned','database':'new_db','integrity_verified':True}
        if args[0]=='upgrade':
            assert 'DB_DATABASE="new_db"' in (root/'.env').read_text()
            return {'status':'ready','version':'1.0.5','backup_id':args[1],'schema_verified':True}
        if args[0]=='quiescent':return {'status':'ok'}
        raise AssertionError(args)

class SafeUpdateTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name)/'current';self.root.mkdir(mode=0o700)
        (self.root/'version').write_text('1.0.4');(self.root/'.env').write_text('DB_DATABASE="old_db"\nSECRET="keep me"\n');(self.root/'.env').chmod(0o600)
        (self.root/'storage').mkdir(mode=0o700);(self.root/'storage'/'data').write_text('durable data')
        self.units=['web.service','queue.service','scheduler.timer']
    def tearDown(self):self.tmp.cleanup()
    def installer(self,fail=None):
        host=FixtureHost(fail);obj=Installer(self.root,host);obj.state.mkdir(mode=0o700,exist_ok=True);return obj,host
    def install(self,obj):return obj.install('1.0.5','new_db',self.units)
    def assert_old(self,obj,host):
        self.assertEqual((self.root/'version').read_text(),'1.0.4');self.assertIn('old_db',(self.root/'.env').read_text());self.assertFalse(host.stopped);self.assertEqual(obj.read()['phase'],'rolled_back')
    def test_success(self):
        obj,host=self.installer();old=inode(self.root);data=self.install(obj)
        self.assertEqual(data['phase'],'complete');self.assertTrue(data['committed']);self.assertNotEqual(inode(self.root),old);self.assertEqual(inode(obj.stage(data)),old)
        self.assertEqual((self.root/'version').read_text(),'1.0.5');self.assertIn('new_db',(self.root/'.env').read_text());self.assertIn('keep me',(self.root/'.env').read_text());self.assertEqual((self.root/'storage'/'data').read_text(),'durable data');self.assertFalse(host.stopped)
        self.assertIn('old_db',(obj.stage(data)/'.env').read_text());self.assertLess(host.calls.index(('verify','1.0.5')),host.calls.index(('stop',tuple(self.units))))
    def test_preverification_failure_never_stops(self):
        obj,host=self.installer('provenance')
        with self.assertRaises(UpdateError):self.install(obj)
        self.assertFalse(host.stopped);self.assertIsNone(obj.read());self.assertEqual((self.root/'version').read_text(),'1.0.4')
    def test_snapshot_clone_migration_and_precommit_health_failures(self):
        for failure in ('snapshot','clone','upgrade','stage_health','quiescent','switched_health'):
            with self.subTest(failure=failure):
                obj,host=self.installer(failure)
                with self.assertRaises(UpdateError):self.install(obj)
                self.assert_old(obj,host)
    def test_postcommit_failure_never_reverts_database(self):
        obj,host=self.installer('post_commit')
        with self.assertRaises(UpdateError):self.install(obj)
        self.assertEqual(obj.read()['phase'],'recovery_required');self.assertTrue(obj.read()['committed']);self.assertTrue(host.stopped);self.assertIn('new_db',(self.root/'.env').read_text())
        host.fail=None;data=obj.recover();self.assertEqual(data['phase'],'complete');self.assertFalse(host.stopped);self.assertIn('new_db',(self.root/'.env').read_text())
    def test_crash_between_swap_and_commit(self):
        obj,host=self.installer();old=inode(self.root);ident='b'*32;stage=obj.state/ident/'release';stage.mkdir(parents=True,mode=0o700);(stage/'version').write_text('1.0.5');(stage/'.env').write_text('DB_DATABASE="new_db"')
        data={'format':1,'id':ident,'phase':'switching','old_inode':old,'new_inode':inode(stage),'units':self.units,'active':self.units,'url':'https://logs.example.test','from':'1.0.4','to':'1.0.5','committed':False};obj.save(data);exchange(self.root,stage)
        obj.recover();self.assert_old(obj,host);self.assertEqual(inode(self.root),old)
    def test_reject_downgrade_and_same_database(self):
        obj,host=self.installer()
        with self.assertRaises(UpdateError):obj.install('1.0.2','new_db',self.units)
        with self.assertRaises(UpdateError):obj.install('1.0.5','old_db',self.units)
        self.assertFalse(host.stopped)
        with self.assertRaises(UpdateError):version_key('../evil')
    def test_storage_symlinks_rejected(self):
        (self.root/'storage'/'link').symlink_to(self.root/'.env');obj,host=self.installer()
        with self.assertRaises(UpdateError):self.install(obj)
        self.assert_old(obj,host)
    def test_env_duplicate_rejected(self):
        p=self.root/'.env';p.write_text('DB_DATABASE=old_db\nDB_DATABASE=other\n')
        with self.assertRaises(UpdateError):change_database(p,'old_db','new_db')
    def test_recover_missing_journal(self):
        obj,_=self.installer()
        with self.assertRaises(UpdateError):obj.recover()

if __name__=='__main__':unittest.main(verbosity=2)

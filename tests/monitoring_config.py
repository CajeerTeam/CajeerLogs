"""Monitoring config generator tests; Docker validation is in production smoke."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('monitor_prepare', ROOT/'deploy/monitoring/prepare.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class ConfigTest(unittest.TestCase):
    def test_secure_target(self):
        p,b,a=m.config('https://logs.example:8443','/metrics',True,True)
        self.assertEqual(p['scrape_configs'][0]['static_configs'][0]['targets'],['logs.example:8443'])
        self.assertEqual(p['scrape_configs'][0]['authorization']['credentials_file'],'/etc/cajeer/metrics-token')
        self.assertFalse(p['scrape_configs'][0]['tls_config']['insecure_skip_verify'])
        self.assertFalse(p['scrape_configs'][0]['follow_redirects'])
        self.assertEqual(b['modules']['https']['http']['tls_config']['ca_file'],'/etc/cajeer/ca.pem')
        self.assertEqual(p['scrape_configs'][2]['static_configs'][0]['targets'],['https://logs.example:8443/ready'])
        self.assertEqual(a['receivers'][0]['webhook_configs'][0]['url_file'],'/etc/cajeer/webhook-url')
    def test_ipv6(self):
        self.assertEqual(m.origin('https://[2001:db8::1]'),('https://[2001:db8::1]','[2001:db8::1]:443'))
    def test_invalid(self):
        for url in ('http://logs.example','https://x:y@logs.example','https://logs.example/path','https://logs.example?x=1','https://logs.example:0','https://logs.example:65536','https://logs.example\n'):
            with self.subTest(url=url),self.assertRaises(ValueError):m.origin(url)
    def test_prepare(self):
        with tempfile.TemporaryDirectory() as temp:
            p=Path(temp); token=p/'metrics-token'; token.write_text('private-token-'+('a'*32)+'\n'); token.chmod(0o600)
            m.prepare(p,'https://logs.example','/metrics')
            self.assertNotIn('private-token',(p/'prometheus.json').read_text()); self.assertEqual((p/'alerts.yml').read_bytes(),(ROOT/'deploy/monitoring/alerts.yml').read_bytes()); self.assertEqual(len(json.loads((p/'prometheus.json').read_text())['scrape_configs']),3)
            token.chmod(0o644)
            with self.assertRaises(ValueError):m.prepare(p,'https://logs.example','/metrics')
            token.chmod(0o600)
            with self.assertRaises(ValueError):m.prepare(p,'https://logs.example','/metrics',True)
if __name__=='__main__': unittest.main()

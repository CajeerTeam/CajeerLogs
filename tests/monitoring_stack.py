"""Real Prometheus/Blackbox scrape and alert evaluation on the production TLS smoke endpoint.

Requires Linux Docker host networking (used only by disposable CI smoke, not deployment).
"""
import contextlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
PROM='prom/prometheus:v3.5.0'; BLACKBOX='prom/blackbox-exporter:v0.27.0'; ALERT='prom/alertmanager:v0.28.1'
spec=importlib.util.spec_from_file_location('monitor_prepare', ROOT/'deploy/monitoring/prepare.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

def port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

def docker(*args, **kwargs): return subprocess.run(['docker',*args],check=True,timeout=300,**kwargs)

class Scrape:
    def __init__(self, endpoint):self.endpoint=endpoint
    def query(self, expr):
        with urllib.request.urlopen(self.endpoint+'/api/v1/query?'+urllib.parse.urlencode({'query':expr}),timeout=5) as r:
            payload=json.load(r)
        assert payload['status']=='success',payload
        return payload['data']['result']
    def expect(self, expr, value, timeout=60):
        until=time.monotonic()+timeout
        while time.monotonic()<until:
            try:
                rows=self.query(expr)
                if len(rows)==1 and float(rows[0]['value'][1])==value:return
            except (OSError,ValueError):pass
            time.sleep(1)
        raise AssertionError(f'Prometheus did not observe {expr}={value}')

@contextlib.contextmanager
def monitoring_smoke(url, token, ca):
    with tempfile.TemporaryDirectory(prefix='cl-monitoring-smoke-') as temp:
        p=Path(temp); (p/'metrics-token').write_text(token+'\n');(p/'metrics-token').chmod(0o600);shutil.copyfile(ca,p/'ca.pem')
        m.prepare(p,url,'/metrics',True)
        shutil.copyfile(ROOT/'deploy/monitoring/alerts.test.yml',p/'alerts.test.yml')
        mount=['-v',f'{p}:/etc/cajeer:ro','--user',f'{os.getuid()}:{os.getgid()}']
        # Parse production configs and exercise PromQL rule tests with actual binaries.
        docker('run','--rm',*mount,'--entrypoint','/bin/promtool',PROM,'check','config','/etc/cajeer/prometheus.json')
        docker('run','--rm',*mount,'-w','/etc/cajeer','--entrypoint','/bin/promtool',PROM,'test','rules','alerts.test.yml')
        docker('run','--rm',*mount,'--entrypoint','/bin/amtool',ALERT,'check-config','/etc/cajeer/alertmanager.json')
        # Host networking reaches the real TLS production gateway on its disposable port.
        # Accelerate scrape cadence only; alert rules themselves are the shipped ones.
        bp,pp=port(),port(); cfg=json.loads((p/'prometheus.json').read_text())
        cfg['global'].update(scrape_interval='5s',scrape_timeout='4s',evaluation_interval='5s')
        cfg['scrape_configs'][0]['scrape_timeout']='4s'
        for job in cfg['scrape_configs'][1:]:
            job['scrape_timeout']='4s';job['relabel_configs'][-1]['replacement']=f'127.0.0.1:{bp}'
        cfg.pop('alerting') # production receiver config checked above; no notifications from tests
        (p/'prometheus.json').write_text(json.dumps(cfg))
        black=json.loads((p/'blackbox.json').read_text());black['modules']['https']['timeout']='3s';(p/'blackbox.json').write_text(json.dumps(black))
        names=['cl-blackbox-'+secrets.token_hex(5),'cl-prometheus-'+secrets.token_hex(5)]
        try:
            docker('run','-d','--name',names[0],'--network','host',*mount,BLACKBOX,'--config.file=/etc/cajeer/blackbox.json',f'--web.listen-address=127.0.0.1:{bp}')
            docker('run','-d','--name',names[1],'--network','host',*mount,PROM,'--config.file=/etc/cajeer/prometheus.json','--storage.tsdb.path=/tmp/cajeer-tsdb',f'--web.listen-address=127.0.0.1:{pp}')
            scrape=Scrape(f'http://127.0.0.1:{pp}')
            scrape.expect('up{job="cajeerlogs"}',1)
            scrape.expect('cajeerlogs_ready',1)
            scrape.expect('probe_success{job="cajeerlogs-health"}',1)
            scrape.expect('probe_success{job="cajeerlogs-ready"}',1)
            scrape.expect('cajeerlogs_worker_healthy{role="queue"}',1)
            scrape.expect('cajeerlogs_worker_healthy{role="scheduler"}',1)
            scrape.expect('cajeerlogs_worker_healthy{role="webhook"}',1)
            scrape.expect('cajeerlogs_backup_status_available',1)
            assert float(scrape.query('probe_ssl_earliest_cert_expiry{job="cajeerlogs-health"}')[0]['value'][1])>time.time()
            yield scrape
        except BaseException:
            for name in names:subprocess.run(['docker','logs','--tail','30',name],check=False)
            raise
        finally:
            for name in names:subprocess.run(['docker','rm','-f',name],check=False,stdout=subprocess.DEVNULL)

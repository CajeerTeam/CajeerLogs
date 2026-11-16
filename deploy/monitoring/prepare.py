#!/usr/bin/env python3
"""Generate an authenticated HTTPS monitoring config; never embed credential values."""
import argparse
import ipaddress
import json
import os
from pathlib import Path
import re
import tempfile
from urllib.parse import urlsplit

ROOT=Path(__file__).resolve().parent

def origin(value):
    u=urlsplit(value)
    if u.scheme!='https' or not u.hostname or u.username or u.password or u.path not in ('','/') or u.query or u.fragment:
        raise ValueError('Use an HTTPS origin, without credentials, path, query or fragment')
    if any(ord(c)<33 or ord(c)>126 for c in value): raise ValueError('Invalid origin characters')
    try: ipaddress.ip_address(u.hostname)
    except ValueError:
        if not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?',u.hostname): raise ValueError('Invalid host')
    port=443 if u.port is None else u.port
    if not 1<=port<=65535: raise ValueError('Invalid port')
    return f'https://{u.netloc}',f'{"["+u.hostname+"]" if ":" in u.hostname else u.hostname}:{port}'

def config(url, metrics_path, private_ca=False, notify=False):
    base,target=origin(url)
    if not re.fullmatch(r'/[A-Za-z0-9_/-]+',metrics_path) or '//' in metrics_path: raise ValueError('Invalid metrics path')
    tls={'insecure_skip_verify':False}
    if private_ca: tls['ca_file']='/etc/cajeer/ca.pem'
    jobs=[{'job_name':'cajeerlogs','scheme':'https','metrics_path':metrics_path,'scrape_timeout':'15s','follow_redirects':False,'authorization':{'type':'Bearer','credentials_file':'/etc/cajeer/metrics-token'},'tls_config':tls,'static_configs':[{'targets':[target]}]}]
    for name,path in [('health','/health'),('ready','/ready')]:
        jobs.append({'job_name':'cajeerlogs-'+name,'metrics_path':'/probe','params':{'module':['https']},'static_configs':[{'targets':[base+path]}], 'relabel_configs':[{'source_labels':['__address__'],'target_label':'__param_target'},{'source_labels':['__param_target'],'target_label':'instance'},{'target_label':'__address__','replacement':'blackbox:9115'}]})
    prom={'global':{'scrape_interval':'30s','scrape_timeout':'15s','evaluation_interval':'30s'},'rule_files':['/etc/cajeer/alerts.yml'],'alerting':{'alertmanagers':[{'static_configs':[{'targets':['alertmanager:9093']}]}]},'scrape_configs':jobs}
    blackbox={'modules':{'https':{'prober':'http','timeout':'10s','http':{'method':'GET','valid_status_codes':[200],'valid_http_versions':['HTTP/1.1','HTTP/2.0'],'fail_if_not_ssl':True,'follow_redirects':False,'tls_config':tls}}}}
    receiver={'name':'operator'}
    if notify: receiver['webhook_configs']=[{'url_file':'/etc/cajeer/webhook-url','send_resolved':True}]
    alert={'route':{'receiver':'operator','group_by':['alertname','instance'],'group_wait':'30s','group_interval':'5m','repeat_interval':'4h'},'receivers':[receiver]}
    return prom,blackbox,alert

def prepare(dest, url, path, ca=None, notify=False):
    # Operator owns input directory; no secrets copied into generated files.
    if not dest.is_absolute() or dest.is_symlink() or not dest.is_dir() or dest.resolve()!=dest:
        raise ValueError('Use an existing absolute canonical config directory')
    if dest.stat().st_mode & 0o022: raise ValueError('Config directory must not be group/world writable')
    for name in ['metrics-token']+(['webhook-url'] if notify else []):
        f=dest/name
        if f.is_symlink() or not f.is_file() or f.stat().st_mode & 0o027 or f.stat().st_size>4096 or not f.stat().st_size: raise ValueError('Missing or invalid '+name)
        raw=f.read_text().strip()
        if not raw or '\n' in raw or '\r' in raw: raise ValueError('Invalid secret file '+name)
        if name=='metrics-token' and not re.fullmatch(r'[A-Za-z0-9._~-]{32,256}',raw): raise ValueError('Invalid metrics token')
        if name=='webhook-url' and (urlsplit(raw).scheme!='https' or not urlsplit(raw).hostname): raise ValueError('Webhook requires HTTPS')
    if ca and ((dest/'ca.pem').is_symlink() or not (dest/'ca.pem').is_file()): raise ValueError('Private CA requires ca.pem in config directory')
    configs=config(url,path,ca,notify)
    for name,data in zip(['prometheus.json','blackbox.json','alertmanager.json'],configs):
        atomic(dest/name,json.dumps(data,indent=2)+'\n')
    atomic(dest/'alerts.yml',(ROOT/'alerts.yml').read_text())

def atomic(path,text):
    fd,temp=tempfile.mkstemp(prefix='.monitor-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as out:
            out.write(text); out.flush(); os.fsync(out.fileno()); os.fchmod(out.fileno(),0o644)
        os.replace(temp,path)
    finally: Path(temp).unlink(missing_ok=True)

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('origin');p.add_argument('directory',type=Path);p.add_argument('--metrics-path',default='/metrics');p.add_argument('--private-ca',action='store_true');p.add_argument('--webhook',action='store_true');a=p.parse_args()
    try: prepare(a.directory,a.origin,a.metrics_path,a.private_ca,a.webhook)
    except (OSError,ValueError) as e: p.exit(1,'Monitoring configuration failed: '+str(e)+'\n')

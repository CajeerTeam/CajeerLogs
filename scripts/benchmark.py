#!/usr/bin/env python3
"""Bounded real HTTP load test. Use an isolated test project; it writes durable events."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import datetime
import hashlib
import hmac
import json
import math
import os
import secrets
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid


def percentiles(samples):
    if not samples:
        return {'count': 0}
    data = sorted(samples)
    return {'count': len(data), **{f'p{p}_ms': round(data[max(0, math.ceil(len(data)*p/100)-1)]*1000, 3)
                                  for p in (50, 95, 99)}, 'max_ms': round(data[-1]*1000, 3)}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # Never forward bearer credentials to a redirected destination.


def run(url, token, project, source, requests=20, batch=10, concurrency=4, timeout=120, cafile=None):
    parsed = urllib.parse.urlsplit(url)
    if (parsed.scheme not in ('https', 'http') or not parsed.hostname or parsed.username or parsed.password
            or parsed.path not in ('', '/') or parsed.query or parsed.fragment
            or (parsed.scheme == 'http' and parsed.hostname not in ('127.0.0.1', 'localhost', '::1'))):
        raise ValueError('Use an HTTPS origin (HTTP only on loopback).')
    project = str(uuid.UUID(project))
    if not 1 <= requests <= 10000 or not 1 <= batch <= 100 or not 1 <= concurrency <= 64 or not 1 <= timeout <= 3600:
        raise ValueError('Invalid benchmark bounds.')
    if not source or not token:
        raise ValueError('Source and CAJEER_TOKEN are required.')
    url = url.rstrip('/')
    marker = 'bench-' + secrets.token_hex(12)
    stamp = datetime.datetime.now(datetime.timezone.utc).isoformat()
    deadline = time.monotonic() + timeout
    context = ssl.create_default_context(cafile=cafile)
    # No ambient proxies: the benchmark is explicitly targeted to this origin.
    def call(method, path, body=None, key=None):
        remaining = deadline-time.monotonic()
        if remaining <= 0:
            raise RuntimeError('Benchmark deadline exceeded')
        headers = {'Authorization': 'Bearer '+token, 'Accept': 'application/json'}
        if body is not None:
            ts = str(int(time.time())); nonce = secrets.token_hex(16)
            signature = hmac.new(token.encode(), '\n'.join(['cajeerlogs-ingest-v1',method,path,ts,nonce,key,hashlib.sha256(body).hexdigest()]).encode(),hashlib.sha256).hexdigest()
            headers.update({'Content-Type':'application/json','Idempotency-Key':key,'X-Log-Timestamp':ts,'X-Log-Nonce':nonce,'X-Log-Signature':signature})
        opener = urllib.request.build_opener(NoRedirect(), urllib.request.ProxyHandler({}), urllib.request.HTTPSHandler(context=context))
        start = time.perf_counter()
        try:
            with opener.open(urllib.request.Request(url+path, data=body, headers=headers, method=method),timeout=min(20,remaining)) as response:
                data=json.loads(response.read(4*1024*1024+1)); status=response.status
        except urllib.error.HTTPError as error:
            status=error.code; error.close(); raise RuntimeError(f'{method} {path.split("?")[0]} returned HTTP {status}') from None
        except (OSError, ValueError):
            raise RuntimeError(f'{method} {path.split("?")[0]} transport/response failure') from None
        return status,data,time.perf_counter()-start

    def writer(i):
        n=1 if i%3==0 else batch
        asynchronous=i%3==2
        events=[{'timestamp':stamp,'source':source,'level':'info','message':f'benchmark {marker} {i}:{j}', 'request_id':marker} for j in range(n)]
        raw=json.dumps(events[0] if n==1 else {'events':events},separators=(',',':')).encode()
        path='/api/v1/ingest'+('/async' if asynchronous else '')
        key=f'{marker}-{i}'
        status,data,latency=call('POST',path,raw,key)
        expected=202 if asynchronous else 201
        if status!=expected or data.get('received')!=n:
            raise RuntimeError('Ingest response mismatch')
        # Replay the same bytes/key with a fresh HMAC nonce. No double count is permitted.
        replay,again,_=call('POST',path,raw,key)
        if replay!=200 or not again.get('replayed'):
            raise RuntimeError('Idempotency replay failed')
        if asynchronous:
            if again.get('job_id')!=data.get('job_id'): raise RuntimeError('Job id changed on replay')
            return ('async',n,data['job_id'],latency)
        ids=data.get('event_ids',[])
        if len(ids)!=n or again.get('event_ids')!=ids: raise RuntimeError('Receipt mismatch')
        return ('single' if n==1 else 'batch',n,ids,latency)

    def search():
        ids=set(); cursor=None; durations=[]
        while True:
            q={'project_id':project,'request_id':marker,'limit':100}
            if cursor is not None: q['cursor']=cursor
            status,d,elapsed=call('GET','/api/v1/logs?'+urllib.parse.urlencode(q));durations.append(elapsed)
            if status!=200: raise RuntimeError('Search failed')
            for item in d['items']:
                if item['request_id']!=marker or item['id'] in ids: raise RuntimeError('Invalid/duplicate search event')
                ids.add(item['id'])
            next_cursor=d.get('next_cursor')
            if not next_cursor: return ids,durations
            if next_cursor==cursor: raise RuntimeError('Cursor did not advance')
            cursor=next_cursor

    started=time.perf_counter(); samples={k:[] for k in ('single','batch','async','search')}; expected_ids=set(); jobs=[]; event_count=0
    with ThreadPoolExecutor(max_workers=concurrency) as pool:
        # One concurrent read during ingestion; final snapshot verification follows completion.
        reader=pool.submit(search)
        futures=[pool.submit(writer,i) for i in range(requests)]
        for f in futures:
            kind,n,result,latency=f.result(); event_count+=n; samples[kind].append(latency)
            if kind=='async': jobs.append((result,n))
            else:
                if len(set(result))!=n or expected_ids.intersection(result): raise RuntimeError('Duplicate synchronous event IDs')
                expected_ids.update(result)
        _,latencies=reader.result();samples['search'].extend(latencies)
    admission_seconds=time.perf_counter()-started
    while jobs:
        remaining=[]
        for jid,n in jobs:
            _,data,_=call('GET','/api/v1/ingest/jobs/'+jid)
            if data['status']=='completed':
                ids=data['result']['event_ids']
                if len(ids)!=n or len(set(ids))!=n or expected_ids.intersection(ids): raise RuntimeError('Duplicate/incomplete async result')
                expected_ids.update(ids)
            elif data['status'] in ('pending','running'): remaining.append((jid,n))
            else: raise RuntimeError('Async job did not complete successfully')
        jobs=remaining
        if jobs: time.sleep(.2)
    found,latencies=search(); samples['search'].extend(latencies)
    if len(expected_ids)!=event_count or found!=expected_ids: raise RuntimeError('Stored event set differs from durable acknowledgements')
    seconds=time.perf_counter()-started
    return {'run_id':marker,'requests':requests,'events_verified':event_count,'concurrency':concurrency,
            'elapsed_seconds':round(seconds,3),'admission_seconds':round(admission_seconds,3),
            'events_per_second_end_to_end':round(event_count/seconds,3),'latency':{k:percentiles(v) for k,v in samples.items()},
            'idempotent_replays_verified':requests,'errors':0}


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--url',required=True);p.add_argument('--project',required=True);p.add_argument('--source',required=True)
    p.add_argument('--requests',type=int,default=20);p.add_argument('--batch',type=int,default=10);p.add_argument('--concurrency',type=int,default=4)
    p.add_argument('--timeout',type=int,default=120);p.add_argument('--ca-file');p.add_argument('--confirm',action='store_true')
    a=p.parse_args()
    if not a.confirm: p.error('Writes real logs: use a disposable project and pass --confirm.')
    try: print(json.dumps(run(a.url,os.environ.get('CAJEER_TOKEN',''),a.project,a.source,a.requests,a.batch,a.concurrency,a.timeout,a.ca_file),indent=2))
    except (RuntimeError,ValueError,KeyError,TypeError) as exc:
        # Avoid printing raw HTTP bodies or credentials on error.
        p.exit(1, 'Benchmark failed: '+(str(exc) if isinstance(exc,(RuntimeError,ValueError)) else type(exc).__name__)+'\n')


if __name__=='__main__': main()

"""Shared end-to-end release scenario for real HTTP/PostgreSQL, including production TLS.
Never used as a substitute for the real database integration suite.
"""
import datetime
import hashlib
import hmac
import json
from pathlib import Path
import secrets
import sys
import time
import urllib.parse

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'clients/python'))
from cajeer_logs_client import CajeerLogsClient, encode


def exercise(http, url, auth, work, ca_file=None):
    # Every run owns a new project and marker. No production data is touched.
    marker='pipeline-'+secrets.token_hex(6)
    project,_=http('POST','/api/v1/projects',{'name':'Pipeline '+marker,'slug':marker},auth,201)
    pid=project['id']; base='/api/v1/projects/'+pid
    a,_=http('POST',base+'/sources',{'name':'Application','slug':'app'},auth,201)
    b,_=http('POST',base+'/sources',{'name':'Worker','slug':'worker'},auth,201)
    http('PUT',base+'/signing',{'required':True},auth)
    http('PUT',base+'/redaction',{'revision':0,'rules':[{'type':'key','value':'customer_email'}]},auth)
    http('PUT',base+'/sources/'+b['id']+'/redaction',{'revision':0,'rules':[{'type':'path','value':'/context/private_reference'}]},auth)
    token,_=http('POST',base+'/tokens',{'name':'Pipeline SDK','scopes':['logs:read','logs:write'],'signing_mode':'separate'},auth,201)
    def client(t):
        return CajeerLogsClient(url,t['token'],signing_key_id=t['signing']['current_key_id'],hmac_secret=t['signing']['signing_secret'],ca_file=ca_file)
    def bearer(t): return {'Authorization':'Bearer '+t['token']}
    def signature(t,path,body,key):
        stamp=str(int(time.time())); nonce=secrets.token_hex(16); signing=t['signing']; kid=signing['current_key_id']
        canonical='\n'.join(['cajeerlogs-ingest-v2',kid,'POST',path,stamp,nonce,key,hashlib.sha256(body).hexdigest()])
        return bearer(t)|{'Idempotency-Key':key,'X-Log-Key-Id':kid,'X-Log-Timestamp':stamp,'X-Log-Nonce':nonce,
            'X-Log-Signature':hmac.new(signing['signing_secret'].encode(),canonical.encode(),hashlib.sha256).hexdigest()}
    stamp=datetime.datetime.now(datetime.timezone.utc).isoformat()
    def event(source,message):
        return {'source':source,'timestamp':stamp,'level':'error','message':message,'request_id':marker,'tags':['release','pipeline'],
                'context':{'customer_email':'synthetic@example.test','private_reference':'synthetic-reference','proof':marker,'precise':'12345678901234567890.123456789'}}
    body=encode([event('app','pipeline database error'),event('worker','pipeline database error')]); key=marker+'-sync'
    http('POST','/api/v1/ingest',body,bearer(token)|{'Idempotency-Key':key},401)
    sdk=client(token); first=sdk.send_body(body,key); again=sdk.send_body(body,key)
    assert first['stored']==2 and again['replayed'] and again['event_ids']==first['event_ids']
    path='/api/v1/ingest/async'; async_key=marker+'-async'; queued_body=encode([event('app','pipeline queued error'),event('worker','pipeline queued error')]); signed=signature(token,path,queued_body,async_key)
    queued,_=http('POST',path,queued_body,signed,202); job=queued['job_id']
    http('POST',path,queued_body,signed,409) # same nonce is rejected even though the request is idempotent
    http('POST','/api/v1/ingest',body,signature(token,'/api/v1/ingest',body,async_key),409)
    # Rotation before the job is completed: the same immutable sync request and
    # async receipt can be recovered with the successor's independent v2 key.
    successor,_=http('POST',base+'/tokens/'+token['id']+'/rotate',{'revision':token['revision'],'expires_in':3600,'overlap_seconds':0},auth,201)
    assert client(successor).send_body(body,key)['event_ids']==first['event_ids']
    replay,_=http('POST',path,queued_body,signature(successor,path,queued_body,async_key)); assert replay['replayed'] and replay['job_id']==job
    http('GET','/api/v1/ingest/jobs/'+job,headers=bearer(token),expected=401)
    work()
    for _ in range(100):
        result,_=http('GET','/api/v1/ingest/jobs/'+job,headers=bearer(successor))
        if result['status']=='completed':break
        assert result['status'] in ('pending','running');time.sleep(.1)
    else:raise AssertionError('Pipeline async job not completed')
    ids=set(first['event_ids']+result['result']['event_ids']);assert len(ids)==4
    filters={'project_id':pid,'request_id':marker,'levels':'["error"]','sources':'["app","worker"]','q':'pipeline error','q_mode':'plain','limit':2,'consistency':'snapshot'}
    def query(filters,endpoint='/api/v1/logs',headers=None,expected=200):
        return http('GET',endpoint+'?'+urllib.parse.urlencode(filters),headers=bearer(successor)|(headers or {}),expected=expected)
    page1,_=query(filters); assert page1['snapshot_at'] and page1['next_cursor'] and page1['prev_cursor'] is None
    # Late arrival with exactly the old occurred_at must not enter the snapshot.
    late=client(successor).ingest(event('app','pipeline late error'),idempotency_key=marker+'-late')['event_ids'][0]
    page2,_=query(filters|{'cursor':page1['next_cursor']});assert page2['snapshot_at']==page1['snapshot_at'] and page2['next_cursor'] is None
    items=page1['items']+page2['items']; assert len(items)==4 and {r['id'] for r in items}==ids and late not in ids
    for r in items:
        assert r['context']['customer_email']=='[redacted]' and r['context']['precise']=='12345678901234567890.123456789'
        assert r['context']['private_reference']==('[redacted]' if r['source']=='worker' else 'synthetic-reference')
    back,_=query(filters|{'cursor':page2['prev_cursor']});assert back['items']==page1['items'] and back['page_digest']==page1['page_digest']
    exported,h=query(filters|{'cursor':page1['page_cursor'],'format':'json'},'/api/v1/logs/export',{'If-Match':'"'+page1['page_digest']+'"'})
    assert exported['items']==page1['items'] and h['X-Page-Digest']==page1['page_digest']
    query(filters|{'cursor':page1['page_cursor'],'format':'json'},'/api/v1/logs/export',{'If-Match':'"'+'0'*64+'"'},412)
    live,_=query(filters|{'consistency':'live','limit':100});assert {r['id'] for r in live['items']}==ids|{late}
    detail,h=query({'project_id':pid},'/api/v1/logs/'+late); assert detail['data']['id']==late and h['Cache-Control']=='no-store'
    scoped,_=http('POST',base+'/tokens',{'name':'Scoped reader','scopes':['logs:read'],'source_id':a['id']},auth,201)
    scoped_rows,_=http('GET','/api/v1/logs?'+urllib.parse.urlencode(filters|{'consistency':'live','limit':100}),headers=bearer(scoped)); assert len(scoped_rows['items'])==3 and all(r['source_id']==a['id'] for r in scoped_rows['items'])
    http('GET','/api/v1/logs?'+urllib.parse.urlencode(filters|{'cursor':page1['page_cursor']}),headers=bearer(scoped),expected=422)
    print('PASS real integrated HMAC v2 / SDK batch / redaction / token rotation / async job / replay / snapshot / export / source isolation',flush=True)
    return {'project_id':pid,'events':5,'token':successor}

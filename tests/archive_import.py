#!/usr/bin/env python3
"""Archive parser and durable checkpoint tests (controlled transport)."""
import json
import sys
import tempfile
from pathlib import Path
from zoneinfo import ZoneInfo
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'clients/python'))
from cajeer_logs_collector import Collector, CollectorError, parse_line

class Client:
    base_url = 'http://127.0.0.1:8080'
    token = 'cl_' + 'a' * 64
    api_prefix = '/api/v1'
    signed = False
    def __init__(self): self.sent = []; self.fail = True
    def send_body(self, body, key):
        self.sent.append((body, key))
        if self.fail: raise RuntimeError('controlled failure')
        return {'accepted': True}

row = {'id':'old','project_id':'old','source_id':'old','source':'app','timestamp':'2026-01-01T00:00:00.000000Z','received_at':'2026-01-02T00:00:00Z','level':'info','message':'archived','context':{'a':1},'tags':['repo']}
raw = json.dumps(row).encode() + b'\n'
event = parse_line(raw,'cajeer-jsonl','app',ZoneInfo('UTC'))
assert event['timestamp']==row['timestamp'] and event['context']==row['context'] and event['source']=='app'
assert not {'id','source_id','project_id','received_at'} & event.keys()
print('PASS archive strips old IDs and preserves event data')
for bad in [row | {'source':'other'}, {k:v for k,v in row.items() if k!='timestamp'}]:
    try: parse_line(json.dumps(bad).encode(),'cajeer-jsonl','app',ZoneInfo('UTC'))
    except ValueError: pass
    else: raise AssertionError('invalid archive accepted')
print('PASS archive source and timestamp checked')
for bad in (b'{"source":"app","source":"app","timestamp":"2026-01-01T00:00:00Z","level":"info","message":"x"}', b'{"source":"app","timestamp":"2026-01-01T00:00:00Z","level":"info","message":1234567890.123456789}'):
    try: parse_line(bad,'cajeer-jsonl','app',ZoneInfo('UTC'))
    except ValueError: pass
    else: raise AssertionError('invalid archive accepted')
assert parse_line(raw.replace(b'"a": 1',b'"a": 0.1234567890123456789'),'cajeer-jsonl','app',ZoneInfo('UTC'))['context']['a']=='0.1234567890123456789'
print('PASS strict JSONL duplicate keys, numeric field types and decimal precision')
with tempfile.TemporaryDirectory() as tmp:
    root = Path(tmp); file = root/'events.jsonl'; state = root/'state.sqlite'; file.write_bytes(raw*3); client=Client()
    collector=Collector(file,state,client,'app','cajeer-jsonl',ZoneInfo('UTC'),batch=2)
    try:
        try: collector.step()
        except RuntimeError: pass
        else: raise AssertionError('expected failure')
        assert collector.row()[2]==0 and collector.row()[4] is not None
    finally: collector.close()
    collector=Collector(file,state,client,'app','cajeer-jsonl',ZoneInfo('UTC'),batch=2)
    try:
        client.fail=False
        assert collector.step()==2 and client.sent[0]==client.sent[1] and collector.row()[2]==len(raw)*2
        assert collector.step()==1 and collector.step()==0 and collector.row()[2]==len(raw)*3
        with file.open('ab') as f: f.write(raw[:-1])
        try: collector.step()
        except CollectorError: pass
        else: raise AssertionError('incomplete archive silently accepted')
        assert collector.row()[2]==len(raw)*3
    finally: collector.close()
print('PASS archive restart, stable receipt key, bounded batch and incomplete-tail refusal')

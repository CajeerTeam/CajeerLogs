"""Called by ingest_database.php against an isolated real PHP/PostgreSQL server."""
import os
import sys
import tempfile
from pathlib import Path
from zoneinfo import ZoneInfo
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'clients/python'))
from cajeer_logs_client import CajeerLogsClient
from cajeer_logs_collector import Collector

client=CajeerLogsClient(os.environ['CL_TEST_URL'],os.environ['CL_TEST_TOKEN'],signed=True)
event={'source':'app','level':'info','message':'sdk real postgres event','timestamp':'2026-01-01T00:00:00Z'}
first=client.ingest(event,idempotency_key='sdk-database-event')
again=client.ingest(event,idempotency_key='sdk-database-event')
assert first['stored']==1 and again['replayed'] and first['event_ids']==again['event_ids']
with tempfile.TemporaryDirectory() as tmp:
    path=Path(tmp)/'log.txt';state=Path(tmp)/'state.sqlite';path.write_text('sdk collector real postgres event\n')
    col=Collector(path,state,client,'app','text',ZoneInfo('UTC'))
    try: assert col.step()==1 and col.step()==0
    finally: col.close()
    col=Collector(path,state,client,'app','text',ZoneInfo('UTC'))
    try: assert col.step()==0
    finally: col.close()
print('PASS Python SDK + collector real PHP/PostgreSQL integration')

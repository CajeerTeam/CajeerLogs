"""Called by ingest_database.php against an isolated real PHP/PostgreSQL server."""
import os
import sys
import tempfile
from pathlib import Path
from zoneinfo import ZoneInfo
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'clients/python'))
from cajeer_logs_client import CajeerLogsClient
from cajeer_batch_spool import DurableBatchSpool
from cajeer_logging import CajeerLogsBufferedHandler
import logging
from cajeer_logs_collector import Collector

client=CajeerLogsClient(os.environ['CL_TEST_URL'],os.environ['CL_TEST_TOKEN'],signed=True)
event={'source':'app','level':'info','message':'sdk real postgres event','timestamp':'2026-01-01T00:00:00Z'}
first=client.ingest(event,idempotency_key='sdk-database-event')
again=client.ingest(event,idempotency_key='sdk-database-event')
assert first['stored']==1 and again['replayed'] and first['event_ids']==again['event_ids']
# Real PostgreSQL-backed async admission: 202 != completed, 200 replay keeps ID.
async_event=event | {'message':'sdk async real postgres event'}
accepted=client.ingest_async(async_event,idempotency_key='sdk-database-async')
replay=client.ingest_async(async_event,idempotency_key='sdk-database-async')
assert accepted['status']=='pending' and accepted['received']==1 and not accepted['replayed']
assert replay['replayed'] and replay['job_id']==accepted['job_id']
assert client.job_status(accepted['job_id'],expected_count=1)['status']=='pending'
with tempfile.TemporaryDirectory() as tmp:
    with DurableBatchSpool(Path(tmp)/'python-062.sqlite',client,batch_events=2) as spool:
        handler=CajeerLogsBufferedHandler(spool,'app',flush_interval=0,fail_on_enqueue_error=True)
        logger=logging.getLogger('python.sdk.database.acceptance')
        logger.setLevel(logging.INFO);logger.addHandler(handler);logger.propagate=False
        logger.info('python 0.6.2 first real DB event',extra={'phase':'e2e'})
        logger.info('python 0.6.2 second real DB event')
        assert spool.status()['queued_events']==2
        assert handler.flush()==2 and spool.status()['delivered_events']==2
        logger.removeHandler(handler);handler.close()
    path=Path(tmp)/'log.txt';state=Path(tmp)/'state.sqlite';path.write_text('sdk collector real postgres event\n')
    col=Collector(path,state,client,'app','text',ZoneInfo('UTC'))
    try: assert col.step()==1 and col.step()==0
    finally: col.close()
    col=Collector(path,state,client,'app','text',ZoneInfo('UTC'))
    try: assert col.step()==0
    finally: col.close()
print('PASS Python sync/async SDK + collector real PHP/PostgreSQL integration')

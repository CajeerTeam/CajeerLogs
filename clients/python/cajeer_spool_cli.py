"""Flush or inspect a durable Python SDK synchronous outbox outside app processes."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import sys

from cajeer_batch_spool import DurableBatchSpool, SpoolError
from cajeer_logs_client import CajeerLogsClient


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('flush', 'status'))
    parser.add_argument('--spool', type=Path, required=True)
    parser.add_argument('--url', default=os.getenv('CAJEER_URL'))
    parser.add_argument('--api-prefix', default='/api/v1')
    parser.add_argument('--signed', action='store_true')
    parser.add_argument('--max-batches', type=int)
    parser.add_argument('--max-events', type=int, default=10000)
    parser.add_argument('--max-bytes', type=int, default=64 * 1024 * 1024)
    parser.add_argument('--batch-events', type=int, default=100)
    parser.add_argument('--batch-bytes', type=int, default=1048576)
    args = parser.parse_args(argv)
    token = os.getenv('CAJEER_TOKEN')
    if not args.url or not token:
        parser.error('CAJEER_URL and CAJEER_TOKEN must be provided (never pass credentials as CLI arguments)')
    try:
        client = CajeerLogsClient(args.url,token,signed=args.signed,
                                  signing_key_id=os.getenv('CAJEER_SIGNING_KEY_ID'),
                                  hmac_secret=os.getenv('CAJEER_SIGNING_SECRET'),
                                  api_prefix=args.api_prefix)
        with DurableBatchSpool(args.spool,client,max_events=args.max_events,max_bytes=args.max_bytes,
                               batch_events=args.batch_events,batch_bytes=args.batch_bytes) as spool:
            if args.command == 'flush':
                result = {'delivered_now':spool.flush(max_batches=args.max_batches), **spool.status()}
            else:
                result = spool.status()
        print(json.dumps(result,sort_keys=True))
        return 0
    except Exception as exc:
        # No URLs, source logs, keys or response bodies in diagnostics.
        detail = 'Local queue requires investigation' if isinstance(exc,SpoolError) else type(exc).__name__
        print(f'CajeerLogs spool operation failed: {detail}',file=sys.stderr)
        return 1

if __name__ == '__main__':
    sys.exit(main())

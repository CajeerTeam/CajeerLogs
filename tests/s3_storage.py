#!/usr/bin/env python3
"""Real local HTTP S3 Signature V4 verification (no SDK mocking)."""
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os
import re
import subprocess
import threading
from pathlib import Path

DATA = {}
ROOT = Path(__file__).resolve().parents[1]


def mac(key, value):
    return hmac.new(key, value.encode(), hashlib.sha256).digest()


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_):
        return

    def handle_request(self):
        try:
            if not self.path.startswith('/cajeer-test/cajeerlogs/archive/v1/'):
                raise ValueError('invalid path')
            auth = self.headers.get('Authorization', '')
            m = re.fullmatch(r'AWS4-HMAC-SHA256 Credential=TESTACCESS/(\d{8})/us-east-1/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([a-f0-9]{64})', auth)
            if not m:
                raise ValueError('invalid auth')
            signed = m[2].split(';')
            fields = {x: self.headers[x] for x in signed}
            if len(fields) != len(signed) or signed != sorted(signed):
                raise ValueError('invalid signed headers')
            body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
            digest = hashlib.sha256(body).hexdigest()
            if digest != self.headers['x-amz-content-sha256']:
                raise ValueError('invalid body digest')
            canonical = (self.command + '\n' + self.path + '\n\n' + ''.join(f'{k}:{fields[k]}\n' for k in signed)
                         + '\n' + ';'.join(signed) + '\n' + digest)
            scope = f'{m[1]}/us-east-1/s3/aws4_request'
            string_to_sign = 'AWS4-HMAC-SHA256\n' + self.headers['x-amz-date'] + '\n' + scope + '\n' + hashlib.sha256(canonical.encode()).hexdigest()
            k = mac(b'AWS4secret-test-signing-key', m[1]); k = mac(k, 'us-east-1')
            k = mac(k, 's3'); k = mac(k, 'aws4_request')
            if not hmac.compare_digest(hmac.new(k, string_to_sign.encode(), hashlib.sha256).hexdigest(), m[3]):
                raise ValueError('signature mismatch')
            if self.command == 'PUT':
                DATA[self.path] = body; status, result = 200, b''
            elif self.command == 'GET':
                status, result = (200, DATA[self.path]) if self.path in DATA else (404, b'')
            elif self.command == 'DELETE':
                DATA.pop(self.path, None); status, result = 204, b''
            else:
                status, result = 405, b''
        except Exception as error:
            status, result = 403, str(error).encode()
        self.send_response(status)
        self.send_header('Content-Length', str(len(result)))
        self.end_headers()
        if result:
            self.wfile.write(result)

    do_PUT = handle_request
    do_GET = handle_request
    do_DELETE = handle_request


if __name__ == '__main__':
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        env = dict(os.environ, S3_TEST_PORT=str(server.server_port))
        subprocess.run(['php', str(ROOT / 'tests/s3_storage.php')], cwd=ROOT, env=env, check=True)
        if env.get('CAJEER_TEST_DATABASE') == '1':
            subprocess.run(['php', str(ROOT / 'tests/storage_tiers_database.php')], cwd=ROOT, env=env, check=True)
    finally:
        server.shutdown()

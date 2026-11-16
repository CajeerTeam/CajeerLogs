"""Real local TLS handshake and certificate checks, no PostgreSQL required."""
import os, socket, subprocess, tempfile, time, json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as td:
    d=Path(td); cert=d/'cert.pem'; key=d/'key.pem'; origin=d/'origin.conf'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-keyout',str(key),'-out',str(cert),'-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],check=True,capture_output=True)
    with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    proc=subprocess.Popen(['openssl','s_server','-accept',f'127.0.0.1:{port}','-cert',str(cert),'-key',str(key),'-www'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',port),.2): break
            except OSError: time.sleep(.1)
        else: raise AssertionError('TLS listener did not start')
        def probe(host,args):
            env=os.environ|{'APP_ENV':'production','APP_URL':f'https://{host}:{port}'}
            return subprocess.run(['php','bin/cajeer','tls:check',*args],cwd=ROOT,env=env,capture_output=True,text=True,timeout=15)
        p=probe('localhost',[str(cert),'0']); assert p.returncode==0,p.stderr; r=json.loads(p.stdout); assert r['status']=='ok' and r['protocol'] in ('TLSv1.2','TLSv1.3') and len(r['sha256_fingerprint'])==64
        p=probe('localhost',[str(cert),'14']); assert p.returncode==1 and json.loads(p.stdout)['status']=='expiring'
        p=probe('127.0.0.1',[str(cert),'0']); assert p.returncode==1 and json.loads(p.stdout)['status']=='failed'
        p=probe('localhost',[]); assert p.returncode==1 and json.loads(p.stdout)['status']=='failed'
        print('PASS live TLS trusted CA, expiration threshold, wrong hostname, untrusted certificate')
    finally:
        proc.terminate(); proc.wait(timeout=5)
    def generate(url):
        return subprocess.run(['sh',str(ROOT/'deploy/production/gateway-origin.sh')],env=os.environ|{'APP_URL':url,'CAJEER_NGINX_ORIGIN_FILE':str(origin)},capture_output=True)
    assert generate('https://logs.example.test').returncode==0 and '"logs.example.test:443" 1;' in origin.read_text()
    assert generate('https://[::1]:8443').returncode==0 and '"[::1]:8443" 1;' in origin.read_text()
    for bad in ['http://example.test','https://evil.test/path','https://evil.test;return 200','https://evil.test\nexample.test','https://evil.test:65536','https://evil.test:0443']:
        assert generate(bad).returncode!=0,bad
    print('PASS gateway origin generation, IPv6, port and injection rejection')

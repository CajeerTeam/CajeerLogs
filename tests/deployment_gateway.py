"""Local real Nginx TLS/Host/header test, independent of PHP-FPM and PostgreSQL.
Requires nginx and openssl. Production smoke separately exercises the Docker backend.
"""
import http.client, os, pathlib, socket, ssl, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='cl-gateway-') as td:
    d=pathlib.Path(td); d.chmod(0o755); key=d/'privkey.pem'; cert=d/'fullchain.pem'; conf=d/'nginx.conf'; origin=d/'origin.conf'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','2','-keyout',str(key),'-out',str(cert),'-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],check=True,capture_output=True)
    with socket.socket() as s: s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    subprocess.run(['sh',str(ROOT/'deploy/production/gateway-origin.sh')],env=os.environ|{'APP_URL':f'https://localhost:{port}','CAJEER_NGINX_ORIGIN_FILE':str(origin)},check=True)
    site=(ROOT/'deploy/production/nginx.conf').read_text().replace('listen 443 ssl;',f'listen 127.0.0.1:{port} ssl;').replace('/tls/fullchain.pem',str(cert)).replace('/tls/privkey.pem',str(key)).replace('/app/public',str(ROOT/'public')).replace('include fastcgi_params;','include /etc/nginx/fastcgi_params;').replace('app:9000','127.0.0.1:1')
    (d/'site.conf').write_text(site)
    conf.write_text(f'pid {d}/nginx.pid; error_log {d}/error.log; worker_processes 1; events {{ worker_connections 128; }} http {{ include /etc/nginx/mime.types; client_body_temp_path {d}/body; fastcgi_temp_path {d}/fastcgi; proxy_temp_path {d}/proxy; uwsgi_temp_path {d}/uwsgi; scgi_temp_path {d}/scgi; include {origin}; include {d}/site.conf; }}')
    cmd=['nginx','-p',str(d)+'/', '-c',str(conf)]
    subprocess.run(cmd+['-t'],check=True,capture_output=True)
    proc=subprocess.Popen(cmd+['-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
        for _ in range(50):
            if proc.poll() is not None: raise AssertionError((d/'error.log').read_text())
            try:
                with socket.create_connection(('127.0.0.1',port),.2): break
            except OSError: time.sleep(.1)
        else: raise AssertionError('Nginx failed to listen')
        ctx=ssl.create_default_context(cafile=str(cert))
        def request(path, host=None):
            c=http.client.HTTPSConnection('localhost',port,context=ctx,timeout=5)
            try:
                c.request('GET',path,headers={'Host':host or f'localhost:{port}'})
                r=c.getresponse(); body=r.read(); return r.status,dict((k.lower(),v) for k,v in r.getheaders()),body
            finally: c.close()
        s,h,b=request('/admin/assets/panel.js'); assert s==200 and b'app/router.js' in b
        assert h['strict-transport-security']=='max-age=31536000' and h['x-frame-options']=='DENY' and h['referrer-policy']=='no-referrer' and h['x-content-type-options']=='nosniff' and 'camera=()' in h['permissions-policy']
        assert request('/admin/assets/panel.js','evil.test')[0]==400
        assert request('/admin/','evil.test')[0]==400
        assert request('/admin/assets/panel.js','localhost')[0]==400
        assert request('/.env')[0]==404 and request('/index.php')[0]==404
        s,h,b=request('/admin/'); assert s==503 and h['x-frame-options']=='DENY' and h['retry-after']=='5' and b'service_unavailable' in b
        print('PASS real Nginx TLS, strict external authority, static headers, private paths, upstream error')
        # New certificate with a distinct key. Invalid intermediate pair must not
        # reload; the already-running worker must still serve the old certificate.
        nextcert=d/'next-cert.pem'; nextkey=d/'next-key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','3','-keyout',str(nextkey),'-out',str(nextcert),'-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],check=True,capture_output=True)
        newctx=ssl.create_default_context(cafile=str(nextcert))
        os.replace(nextcert,cert)
        assert subprocess.run(cmd+['-t'],capture_output=True).returncode!=0
        assert request('/admin/assets/panel.js')[0]==200
        os.replace(nextkey,key)
        subprocess.run(cmd+['-t'],check=True,capture_output=True); subprocess.run(cmd+['-s','reload'],check=True,capture_output=True)
        ctx=newctx
        for _ in range(50):
            try:
                if request('/admin/assets/panel.js')[0]==200: break
            except (OSError,ssl.SSLError): pass
            time.sleep(.1)
        else: raise AssertionError('New certificate not served after reload')
        print('PASS rejected mismatched key, old worker remains available, validated reload serves new certificate')
    finally:
        proc.terminate(); proc.wait(timeout=5)

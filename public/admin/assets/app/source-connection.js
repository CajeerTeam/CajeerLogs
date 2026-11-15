// Shared by the existing source test and the onboarding wizard. Never persist credentials.
const encoder = new TextEncoder();
const hex = value => Array.from(new Uint8Array(value), b => b.toString(16).padStart(2,'0')).join('');
export function testPayload(source, now = new Date(), random = globalThis.crypto) {
    const id = hex(random.getRandomValues(new Uint8Array(16)));
    return {key:'source-test-'+id, body:JSON.stringify({source,level:'info',message:'CajeerLogs source connection test',timestamp:now.toISOString(),request_id:'source-test-'+id})};
}
export async function signedHeaders({endpoint,body,key,token,kid='',secret='',timestamp=String(Math.floor(Date.now()/1000)),crypto=globalThis.crypto,nonce}) {
    if(!crypto?.subtle) throw new Error('Для подписи запроса требуется HTTPS или localhost.');
    if(!token || ((kid||secret)&&(!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(kid)||!/^sk_[a-f0-9]{64}$/.test(secret)))) throw new Error('Укажите корректный токен и, для HMAC v2, ID и секрет ключа.');
    nonce ??= hex(crypto.getRandomValues(new Uint8Array(16)));
    const digest=hex(await crypto.subtle.digest('SHA-256',encoder.encode(body)));
    const signingKey=await crypto.subtle.importKey('raw',encoder.encode(secret||token),{name:'HMAC',hash:'SHA-256'},false,['sign']);
    const canonical=[...(kid?['cajeerlogs-ingest-v2',kid]:['cajeerlogs-ingest-v1']),'POST',endpoint,timestamp,nonce,key,digest].join('\n');
    const signature=hex(await crypto.subtle.sign('HMAC',signingKey,encoder.encode(canonical)));
    return {'Idempotency-Key':key,'X-Log-Timestamp':timestamp,'X-Log-Nonce':nonce,'X-Log-Signature':signature,...(kid?{'X-Log-Key-Id':kid}:{})};
}
export function receipt(status,result) {
    if(!result?.accepted || result.received!==1 || result.rejected!==0 || !((status===201&&result.stored===1&&result.replayed===false)||(status===200&&result.stored===0&&result.replayed===true)) || !Array.isArray(result.event_ids) || result.event_ids.length!==1 || !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(result.event_ids?.[0]||'')) throw new Error('Не удалось подтвердить сохранение тестового события.');
    return result.event_ids[0];
}
// These are the configured project/source constraints, not remaining quota or global limits.
export function effectivePolicy(project,source) {
    const min=(a,b)=>a==null?b??null:b==null?a:Math.min(a,b);
    return {retention_days:min(project.retention_days,source.retention_days),max_body_bytes:min(project.max_body_bytes,source.max_body_bytes),max_batch_events:min(project.max_batch_events,source.max_batch_events),project_events_per_minute:project.events_per_minute??null,source_events_per_minute:source.events_per_minute??null};
}
// Standalone signed HTTP integration, using only Python's standard library. No secret in generated code.
export function pythonExample(endpoint,source) {
    return `import os, json, time, uuid, hashlib, hmac, urllib.request, urllib.parse\nfrom datetime import datetime, timezone\n\nurl = ${JSON.stringify(endpoint)}\nsource = ${JSON.stringify(source)}\ntoken = os.environ['CAJEER_TOKEN']\nkid = os.environ.get('CAJEER_SIGNING_KEY_ID', '')\nsecret = os.environ.get('CAJEER_SIGNING_SECRET', '')\nif bool(kid) != bool(secret):\n    raise ValueError('Set both HMAC v2 variables or neither')\nkey = 'app-' + uuid.uuid4().hex\nbody = json.dumps({'source': source, 'level': 'info', 'message': 'Hello CajeerLogs',\n                   'timestamp': datetime.now(timezone.utc).isoformat()},\n                  separators=(',', ':')).encode('utf-8')\ntimestamp = str(int(time.time()))\nnonce = uuid.uuid4().hex\nparts = ['cajeerlogs-ingest-v2', kid] if kid else ['cajeerlogs-ingest-v1']\nparts += ['POST', urllib.parse.urlsplit(url).path, timestamp, nonce, key, hashlib.sha256(body).hexdigest()]\nsignature = hmac.new((secret or token).encode(), '\\n'.join(parts).encode(), hashlib.sha256).hexdigest()\nheaders = {'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json',\n           'Idempotency-Key': key, 'X-Log-Timestamp': timestamp,\n           'X-Log-Nonce': nonce, 'X-Log-Signature': signature}\nif kid:\n    headers['X-Log-Key-Id'] = kid\nrequest = urllib.request.Request(url, data=body, headers=headers, method='POST')\n# Do not forward credentials to a redirect target.\nclass NoRedirect(urllib.request.HTTPRedirectHandler):\n    def redirect_request(self, req, fp, code, msg, headers, newurl):\n        return None\nwith urllib.request.build_opener(NoRedirect()).open(request, timeout=30) as response:\n    print(response.read().decode())\n`;
}

# Records the cloud cutover responses (Workstream O) from a running Registry Service. See README.md.
#   python3 -I record_cloud.py http://127.0.0.1:8124 <platform secret> . "<artisan command prefix>"
import hashlib, hmac, json, os, shlex, subprocess, sys, time, urllib.error, urllib.request

HOST, SECRET, OUT, ARTISAN = sys.argv[1:5]
CLOUD_HOST = 'https://api.fleetbase.test'
BETA = 'c0000000-0000-4000-8000-000000000002'
TOKENS = {}


def call(method, path, body=None, token=None, headers=None, sign=False):
    data = json.dumps(body, separators=(',', ':')).encode() if body is not None else None
    req = urllib.request.Request(HOST + path, data=data, method=method)
    req.add_header('Accept', 'application/json')
    if data is not None:
        req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    if sign:
        ts, nonce = str(int(time.time())), os.urandom(16).hex()
        mac = hmac.new(SECRET.encode(), (ts + '.' + nonce + '.').encode() + data, hashlib.sha256).hexdigest()
        req.add_header('X-Registry-Timestamp', ts)
        req.add_header('X-Registry-Nonce', nonce)
        req.add_header('X-Registry-Signature', 'v1=' + mac)
    for key, value in (headers or {}).items():
        req.add_header(key, value)
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'null')


def placeholder(payload):
    text = json.dumps(payload)
    for real, fake in TOKENS.items():
        text = text.replace(real, fake)
    return json.loads(text)


def save(name, method, path, **kwargs):
    status, payload = call(method, path, **kwargs)
    if isinstance(payload, dict) and isinstance(payload.get('token'), str):
        TOKENS[payload['token']] = 'flbi_cloud' + str(len(TOKENS)).rjust(35, '0')
    with open(f'{OUT}/{name}.json', 'w') as f:
        json.dump({'request': {'method': method, 'path': path.split('?')[0]}, 'status': status, 'body': placeholder(payload)}, f, indent=4, ensure_ascii=False)
        f.write('\n')
    print(name, status)
    return payload


def artisan(*args):
    subprocess.run(shlex.split(ARTISAN) + list(args), check=True, capture_output=True)


register = {'name': 'Fleetbase Cloud', 'host': CLOUD_HOST, 'console_version': '0.8.0', 'api_version': '1.6.80'}
save('instances-register-cloud-unsigned', 'POST', '/v1/instances/register/cloud', body=register)
token = save('instances-register-cloud-rotated', 'POST', '/v1/instances/register/cloud', body=register, sign=True)['token']
save('instances-register-cloud', 'POST', '/v1/instances/register/cloud', body={**register, 'host': 'https://api.staging.fleetbase.test'}, sign=True)
tenant = {'X-Registry-Tenant': BETA, 'X-Registry-Tenant-Name': 'Beta%20Freight'}
save('instances-me-cloud-tenant', 'GET', '/v1/instances/me', token=token, headers=tenant)
save('instances-tenant-installs-page-1', 'GET', '/v1/instances/me/tenant-installs?per_page=2&page=1', token=token)
save('instances-tenant-installs-page-2', 'GET', '/v1/instances/me/tenant-installs?per_page=2&page=2', token=token)
artisan('registry:maintenance', 'on', '--message=Extensions are moving to the new registry until 14:00 UTC.')
save('checkout-maintenance', 'POST', '/v1/instances/me/purchases/checkout', token=token, headers=tenant,
     body={'extension': 'route-planner', 'return_url': 'https://console.fleetbase.test/extensions?purchase_return=1'})
artisan('registry:maintenance', 'off')

import json, sys, subprocess, urllib.request, urllib.error
HOST, TOKEN, OUT, SVC = sys.argv[1:5]
def call(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(HOST + path, data=data, method=method)
    req.add_header('Accept', 'application/json')
    if data is not None: req.add_header('Content-Type', 'application/json')
    req.add_header('Authorization', 'Bearer ' + TOKEN)
    try:
        with urllib.request.urlopen(req) as r: return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e: return e.code, json.loads(e.read() or b'null')
def tinker(code):
    subprocess.run(['php', 'artisan', 'tinker', '--execute', code], cwd=SVC, check=True, capture_output=True)
def save(name, method, path, body=None):
    status, payload = call(method, path, body)
    with open(f'{OUT}/{name}.json', 'w') as f:
        json.dump({'request': {'method': method, 'path': path.split('?')[0]}, 'status': status, 'body': payload}, f, indent=4)
        f.write('\n')
    print(name, status)
    return payload
handoffs = json.loads(sys.argv[5])
save('catalog-extensions-every-hosting', 'GET', '/v1/catalog/extensions?limit=30')
save('catalog-releases', 'GET', '/v1/catalog/extensions/registry-demo-hello/releases')
save('catalog-releases-not-found', 'GET', '/v1/catalog/extensions/does-not-exist/releases')
save('instances-me-purchased', 'GET', '/v1/instances/me')
ret = {'extension': 'acme-route-planner', 'return_url': 'https://fleet.example.test/extensions?extension_id=ext_x&purchase_return=1', 'cancel_url': 'https://fleet.example.test/extensions?extension_id=ext_x&purchase_return=1', 'email': 'ops@example.test'}
save('checkout-already-licensed', 'POST', '/v1/instances/me/purchases/checkout', ret)
tinker('App\\Models\\Licence::whereHas("extension", fn($q) => $q->where("slug","acme-route-planner"))->update(["status" => "revoked"]);')
save('checkout-no-stripe', 'POST', '/v1/instances/me/purchases/checkout', ret)
tinker('App\\Models\\Licence::whereHas("extension", fn($q) => $q->where("slug","acme-route-planner"))->update(["status" => "active"]);')
save('checkout-hosting-mismatch', 'POST', '/v1/instances/me/purchases/checkout', {**ret, 'extension': 'acme-fleet-insights'})
save('checkout-unknown-extension', 'POST', '/v1/instances/me/purchases/checkout', {**ret, 'extension': 'does-not-exist'})
save('checkout-validation', 'POST', '/v1/instances/me/purchases/checkout', {'extension': 'acme-route-planner'})
save('purchases-handoff-open', 'GET', '/v1/instances/me/purchases/' + handoffs[0])
save('purchases-handoff-expired', 'GET', '/v1/instances/me/purchases/' + handoffs[1])
save('purchases-handoff-complete', 'GET', '/v1/instances/me/purchases/' + handoffs[2])
save('purchases-handoff-not-found', 'GET', '/v1/instances/me/purchases/chk_doesnotexist')

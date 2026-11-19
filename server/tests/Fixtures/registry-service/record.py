"""Record Registry Service responses as fixtures for registry-bridge Pest tests.

Usage: SVC_DIR=<service checkout> python3 -I record.py <service url> <buyer account token> <outsider account token> <out dir>

Needs a freshly seeded service (`php artisan migrate:fresh --seed && php artisan registry:seed --seed-release`)
and account tokens with the `instance:register` scope for the buyer and outsider demo accounts.
Instance tokens in responses are replaced with stable placeholders so fixtures carry no live secret.
"""
import json
import os
import sys
import urllib.error
import urllib.request

HOST, BUYER, OUTSIDER, OUT = sys.argv[1:5]
os.makedirs(OUT, exist_ok=True)
REDACT = {}
# Publishes registry-demo-hello 1.1.0 by copying the 1.0.0 release, so updates have something to report.
PUBLISH = (
    '$r = App\Models\Release::where("version", "1.0.0")->whereHas("extension", fn ($q) => $q->where("slug", "registry-demo-hello"))->first();'
    '$n = $r->replicate(); $n->id = null; $n->uuid = null; $n->public_id = null; $n->version = "1.1.0"; $n->notes = "Adds a greeting.";'
    '$n->published_at = now()->addMinute(); $n->created_at = now()->addMinute(); $n->save(); echo $n->public_id;'
)


def call(method, path, token=None, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(HOST + path, data=data, method=method)
    req.add_header('Accept', 'application/json')
    if data is not None:
        req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(req) as r:
            return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'null')


def save(name, method, path, status, body):
    text = json.dumps(body, indent=4)
    for real, fake in REDACT.items():
        text = text.replace(real, fake)
    record = {'request': {'method': method, 'path': path}, 'status': status, 'body': json.loads(text)}
    with open(os.path.join(OUT, name + '.json'), 'w') as f:
        f.write(json.dumps(record, indent=4) + '\n')
    print(name, status)


def rec(name, method, path, token=None, body=None):
    status, payload = call(method, path, token, body)
    save(name, method, path, status, payload)
    return status, payload


def register(account_token, org, name, label):
    _, code = call('POST', '/v1/account/instance-codes', account_token, {'organization': org})
    body = {'code': code['code'], 'name': name, 'host': 'https://fleet.example.test', 'kind': 'self_hosted',
            'console_version': '0.7.69', 'api_version': '1.6.30'}
    status, payload = call('POST', '/v1/instances/register', None, body)
    REDACT[payload['token']] = 'flbi_' + label.ljust(40, '0')[:40]
    save('instances-register' if label == 'buyer' else 'instances-register-' + label, 'POST', '/v1/instances/register', status, payload)
    return payload['token']


rec('catalog-extensions', 'GET', '/v1/catalog/extensions')
rec('catalog-extensions-search', 'GET', '/v1/catalog/extensions?q=pro&limit=1')
rec('catalog-extension', 'GET', '/v1/catalog/extensions/registry-demo-pro')
rec('catalog-extension-not-found', 'GET', '/v1/catalog/extensions/does-not-exist')
rec('catalog-categories', 'GET', '/v1/catalog/categories')

rec('instances-register-invalid-code', 'POST', '/v1/instances/register', None,
    {'code': 'AAAA-BBBB-CCCC-DDDD', 'name': 'Bad', 'kind': 'self_hosted'})
rec('instances-register-validation', 'POST', '/v1/instances/register', None, {'name': 'No code'})

token = register(BUYER, 'registry-demo-buyer', 'Acme Fleet (self-hosted)', 'buyer')
outsider = register(OUTSIDER, 'registry-demo-outsider', 'Outsider Fleet', 'outsider')

rec('instances-me-unauthenticated', 'GET', '/v1/instances/me')
rec('instances-me', 'GET', '/v1/instances/me', token)
rec('instances-installs-installing', 'POST', '/v1/instances/me/installs', token,
    {'extension': '@registry-demo/hello-engine', 'version': '1.0.0', 'status': 'installing', 'installed_by': 'admin@example.test'})
rec('instances-installs-installed', 'POST', '/v1/instances/me/installs', token,
    {'extension': 'registry-demo-hello', 'version': '1.0.0', 'status': 'installed', 'installed_by': 'admin@example.test'})
rec('instances-installs-failed', 'POST', '/v1/instances/me/installs', token,
    {'extension': 'registry-demo-pro', 'version': '1.0.0', 'status': 'failed', 'error': 'composer require failed'})
rec('instances-installs-paid-forbidden', 'POST', '/v1/instances/me/installs', outsider,
    {'extension': 'registry-demo-pro', 'version': '1.0.0', 'status': 'installing'})
rec('instances-installs-validation', 'POST', '/v1/instances/me/installs', token, {'extension': 'registry-demo-hello'})
rec('instances-me-installed', 'GET', '/v1/instances/me', token)
rec('instances-me-updates-current', 'GET', '/v1/instances/me/updates', token)

import subprocess
subprocess.run(['php', 'artisan', 'tinker', '--execute', PUBLISH], cwd=os.environ['SVC_DIR'], check=True)
rec('instances-me-updates', 'GET', '/v1/instances/me/updates', token)
rec('instances-installs-uninstall', 'DELETE', '/v1/instances/me/installs/registry-demo-hello', token)
rec('instances-installs-uninstall-not-installed', 'DELETE', '/v1/instances/me/installs/@registry-demo/pro-engine', token)

# Recorded Registry Service responses

Responses from a running Registry Service (`fleetbase/registry` branch `v2`, commit `9aa8443`), recorded on
2026-10-22 by `record.py` and replayed by `server/tests/Support/RegistryServiceFake.php`. Each file is
`{request: {method, path}, status, body}`.

The data comes from `php artisan registry:seed --seed-release`: a free extension (`registry-demo-hello`) and a paid
one (`registry-demo-pro`) published by `registry-demo`, a buyer organization holding a paid licence for the paid
one, and an outsider organization with none. Before the `instances-me-updates` and later requests, the recorder
publishes `registry-demo-hello` 1.1.0 so the updates endpoint has something to report.

Instance tokens are replaced with `flbi_buyer000…` and `flbi_outsider…` placeholders.

To re-record, run the service locally (see its README), seed it as above, mint `instance:register` account tokens
for `buyer@registry-demo.test` and `outsider@registry-demo.test` (for example `App\Models\Token::issue($account,
'reg', ['instance:register'], now()->addDay())` in `php artisan tinker`), then:

```bash
SVC_DIR=/path/to/registry python3 -I record.py http://127.0.0.1:8000 <buyer token> <outsider token> .
```

Ids change on every run, so tests that assert on ids need updating with the new values.

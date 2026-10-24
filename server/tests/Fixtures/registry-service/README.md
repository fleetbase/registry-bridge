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

`instances-register-rotated.json` is not recorded: it is `instances-register.json` with the 200 and `rotated: true`
the service returns when an instance registers again (fleetbase/registry `release/v2.0.0` 68331fe), and a new token.

Ids change on every run, so tests that assert on ids need updating with the new values.

## Cloud cutover (Workstream O)

`record_cloud.py` records the cloud instance's responses from `fleetbase/registry` branch `feat/cloud-cutover`
(on `release/v2.0.0`), recorded on 2026-10-24: a SQLite service with `REGISTRY_CLOUD_PLATFORM_SECRET` and
`REGISTRY_CLOUD_HOST=https://api.fleetbase.test` set, after `php artisan registry:import --from=tests/Fixtures/import/cloud-registry.sql`
(the importer's fixture: companies Acme, Beta and Gamma, Beta holding a paid licence for `fleet-insights`). So the
first signed registration rotates onto the cloud instance the import prepared (`instances-register-cloud-rotated`),
and one from another host creates a second deployment's instance (`instances-register-cloud`).
`instances-tenant-installs-page-*` were read with `per_page=2` to have two pages. `checkout-maintenance` is a
checkout while `registry:maintenance on` was set.

```bash
python3 -I record_cloud.py http://127.0.0.1:8124 <platform secret> . "php /path/to/registry/artisan"
```

Instance tokens are replaced with `flbi_cloud000…` placeholders.

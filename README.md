<p align="center">
    <img src="https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/fleetbase-logo-svg.svg" alt="Fleetbase" width="380" height="100">
</p>

<p align="center">
    The Extensions screens of the Fleetbase console, and the installer that puts extensions on your instance.
</p>

<p align="center">
    <a href="https://github.com/fleetbase/registry-bridge">
        <img src="https://img.shields.io/badge/repo-fleetbase%2Fregistry--bridge-111827?style=flat-square" alt="Repository">
    </a>
    <a href="https://github.com/fleetbase/registry-bridge/blob/main/LICENSE.md">
        <img src="https://img.shields.io/badge/license-AGPL--3.0--or--later-blue?style=flat-square" alt="License: AGPL-3.0-or-later">
    </a>
    <a href="https://codecov.io/gh/fleetbase/registry-bridge">
        <img src="https://codecov.io/gh/fleetbase/registry-bridge/branch/main/graph/badge.svg" alt="Coverage">
    </a>
    <a href="https://www.npmjs.com/package/@fleetbase/registry-bridge-engine">
        <img src="https://img.shields.io/badge/npm-%40fleetbase%2Fregistry--bridge--engine-CB3837?style=flat-square" alt="NPM package">
    </a>
    <a href="https://packagist.org/packages/fleetbase/registry-bridge">
        <img src="https://img.shields.io/badge/packagist-fleetbase%2Fregistry--bridge-F28D1A?style=flat-square" alt="Packagist package">
    </a>
    <a href="https://www.fleetbase.io/docs/extension-development">
        <img src="https://img.shields.io/badge/docs-extensions-0EA5E9?style=flat-square" alt="Extension documentation">
    </a>
    <img src="https://img.shields.io/badge/node-%3E%3D18-339933?style=flat-square" alt="Node >= 18">
    <img src="https://img.shields.io/badge/php-%5E8.2-777BB4?style=flat-square" alt="PHP ^8.2">
</p>

## What is Registry Bridge?

Registry Bridge connects a [Fleetbase](https://www.fleetbase.io) instance to the [Fleetbase Registry Service](https://github.com/fleetbase/registry), where extensions are published, reviewed and sold. It adds the **Extensions** section to the console and gives the API an installer that downloads an extension, installs it and rolls back if anything fails.

Registry Bridge ships as two packages:

| Package | Purpose |
| --- | --- |
| `@fleetbase/registry-bridge-engine` | Ember Engine that provides the Extensions screens in the console. |
| `fleetbase/registry-bridge` | Laravel package with the bridge API, the extension installer and the `registry:*` artisan commands. |

It is included with Fleetbase Cloud and with self-hosted Fleetbase. Version 0.3 of the bridge, which this README describes, ships with Fleetbase v0.9.0 and later. Publishing, review and payouts are not part of the bridge: they live on the Registry Service's website, which the console links to as the publisher portal.

## Features

### Extensions in the console

- **Discover** shows featured extensions, categories and search, as cards or a list.
- **Extension pages** show screenshots, highlights, setup steps, release notes, links and the publisher.
- **Installed**, **Updates** and **Purchased** list what the organization has, what can be updated and what it has bought.
- Every extension carries a compatibility verdict for this instance: *Works with your Fleetbase*, *Needs a Fleetbase update* or *Not available for your setup*. An extension that can't be installed stays listed, with the reason.
- One button follows the extension's state: Get, Buy, the install progress, Open or Update.

### Installing, updating and removing

- Installs, updates and removals run as queued jobs, one at a time per instance. The console follows each job live.
- A job snapshots the instance before it changes anything. Any failure restores the snapshot and reports the stage that failed with the last lines of the log.
- An extension that ships a prebuilt console engine is loaded at runtime, so the console isn't rebuilt.
- Each installed extension has an auto-update policy: none, patch releases or minor releases.
- `registry:compat` checks every installed extension against a target Fleetbase version before an upgrade.

### Paid and private extensions

- Paid extensions are bought through Stripe Checkout, hosted by the Registry Service. The console starts the checkout and waits for the licence.
- Private extensions appear only for organizations that hold an access grant or a licence.
- The service checks the licence again when an install starts.

### Administration

- **Registry Config** (admin panel) sets the service URL and registers the instance with a one-time code.
- The instance token is stored encrypted with the app key and is only ever shown masked.
- On Fleetbase Cloud the API registers itself as the one cloud instance and acts for each organization.

## How it works

```text
Console (Ember)                 Fleetbase API (Laravel)                 Registry Service
+--------------------+          +----------------------------+          +----------------------+
| Extensions screens | -------> | Bridge API   ~registry/v1  | -------> | Catalog API          |
| install progress   | <------- | Installer    queue job     | <------- | npm + Composer       |
+--------------------+  socket  | registry:*   artisan       |          | Instance API         |
                                +----------------------------+          +----------------------+
```

1. The console asks the bridge API for the catalog. The bridge reads it from the Registry Service and adds what it knows locally: what is installed, what was bought, and whether this instance can install each release.
2. An install request creates a job on the `installer` queue and returns its id. The console polls the job and listens on the socket for each step.
3. The job resolves the release, downloads it and verifies its checksums, snapshots the instance, runs Composer, adds the console engine, clears caches and records the install. The service is told when the install starts, and whether it finished or failed.

```text
registry-bridge/
+-- addon/              # Ember Engine source: routes, components, services, templates, utils
+-- app/                # Ember app re-exports
+-- translations/       # Locale files
+-- server/
|   +-- config/         # registry-bridge.php
|   +-- migrations/     # Database migrations
|   +-- resources/      # The npm config template used for installs
|   +-- src/            # Controllers, console commands, jobs, models, the installer
|   +-- tests/          # Pest test suite
+-- tests/              # Ember test suite
+-- package.json        # Frontend package metadata and scripts
+-- composer.json       # Backend package metadata and scripts
+-- extension.json      # Fleetbase extension metadata
```

## Getting started

Registry Bridge comes with Fleetbase, so there is nothing to install. To use it on a self-hosted instance, connect the instance to the registry once:

1. Get a one-time registration code for your registry organization. An owner or admin of the organization creates it, for example with the [Fleetbase CLI](https://github.com/fleetbase/fleetbase-cli):

   ```bash
   flb instance code
   ```

2. In the console, open **Admin → Extensions Registry → Registry Config** and enter the code.

The same registration from the instance's command line:

```bash
php artisan registry:register --code=<code>
```

Or let the CLI do both steps. It creates the code and passes it to the instance, so the token never leaves the instance:

```bash
flb instance register --docker
```

Registering an instance that is already registered rotates its token instead of creating a second instance. The instance keeps its id, its installs and its licence seats. A new host, or a code from another organization, registers a new instance.

### The installer's worker

Installs run on the `installer` queue, so a queue worker has to work it:

```bash
php artisan queue:work --queue=installer --timeout=7200 --tries=1
```

The official Docker image starts this worker beside the API when `REGISTRY_INSTALLER_WORKER=true`, which the stock `docker-compose.yml` sets. Where no worker can run, `REGISTRY_INSTALLER_ASYNC=false` runs each job inside the request.

## Configuration

Settings live in [`server/config/registry-bridge.php`](server/config/registry-bridge.php). The service URL and the registration are stored in the `settings` table and can be edited in Registry Config; the environment provides the defaults.

### Registry

| Variable | Default | Purpose |
| --- | --- | --- |
| `REGISTRY_SERVICE_URL` | `https://registry.fleetbase.io` | The Registry Service this instance uses. |
| `REGISTRY_INSTANCE_KIND` | `self_hosted` | `self_hosted` or `cloud`. Reported at registration and matched against each extension's hosting. |
| `REGISTRY_INSTANCE_TOKEN` | none | The instance token. Normally issued by registering and stored encrypted in settings instead. |
| `REGISTRY_PUBLISHER_URL` | the service URL | The publisher portal the console links to. |
| `REGISTRY_SERVICE_CHECKOUT` | `auto` | `auto` turns purchases on for a registered instance unless the service has no checkout. Or `true`, `false`. |
| `REGISTRY_SETTINGS_CACHE_TTL` | `30` | Seconds the stored registry settings stay cached between requests. `0` reads the table on every request. |
| `REGISTRY_MAINTENANCE` | `false` | Refuses checkout, install, update and uninstall, and shows a banner. `registry:maintenance on` does the same at runtime. |

Stored settings are read per request through a short cache that is cleared whenever one of them changes. A registration made in another process therefore reaches every web and queue worker without `octane:reload` or `queue:restart`.

### Installer

| Variable | Default | Purpose |
| --- | --- | --- |
| `REGISTRY_INSTALLER_ASYNC` | `true` | Queue installs and return a job id. `false` runs the job inside the request. |
| `REGISTRY_INSTALLER_QUEUE` | `installer` | The queue the jobs go on. |
| `REGISTRY_INSTALLER_CONNECTION` | the default connection | The queue connection. |
| `REGISTRY_INSTALLER_CACHE_STORE` | the default store | Holds job records, the queue order and the instance lock. It must be shared by the web and queue processes and support locks (Redis, database, file). |
| `REGISTRY_INSTALLER_RELOAD_OCTANE` | `false` | Run `octane:reload` after each job, so Octane's workers load the change. For a worker in the same container as Octane. |
| `REGISTRY_INSTALLER_CONSOLE_BUILD` | automatic | Whether the installer may rebuild the console for an extension without a prebuilt engine. Unset: only when the console is on the same host. |
| `REGISTRY_INSTALLER_TIMEOUT` | `7200` | Seconds a job may run. |
| `REGISTRY_INSTALLER_REQUIRE_CHECKSUMS` | `false` | Refuse a release that has no checksums. |
| `REGISTRY_PREINSTALLED_EXTENSIONS` | `false` | For images that ship every extension already installed (Fleetbase Cloud). An install only changes the install record: nothing is queued and no code is downloaded. |

Composer and pnpm get the instance's credentials through the process environment only. No `auth.json` or `.npmrc` holding a token is written.

### Runtime engines

A release can carry its console engine prebuilt. The installer checks it against the console's `host-modules.json`, unpacks it and adds it to the manifest at `GET ~registry/v1/engines/runtime`, which the console reads at boot. Without a prebuilt engine the installer falls back to `pnpm add` and a console build, which needs a console checkout beside the API.

| Variable | Default | Purpose |
| --- | --- | --- |
| `REGISTRY_RUNTIME_ENGINES_BASE_URL` | none | Unset: the API serves the engine files at `~registry/v1/engines/files`, so the console needs no access to the folder. Set (for example `/engines-dist/`) when the console serves the folder itself. |
| `REGISTRY_RUNTIME_ENGINES_PATH` | `storage/app/registry-runtime-engines` | Where engines are unpacked, one folder per package. Keep it on a volume. |
| `REGISTRY_HOST_MODULES_PATH` | `<console>/dist/host-modules.json` | The console's `host-modules.json`: a path, or a URL where the console serves it. Production console builds write it; `FLEETBASE_HOST_MODULES=1` makes `ember serve` write it too. |
| `REGISTRY_ENGINE_COMPAT_POLICY` | `minor` | How closely a prebuilt engine must match the console's host modules: `exact`, `minor` or `major`. |

### Auto-update

| Variable | Default | Purpose |
| --- | --- | --- |
| `REGISTRY_AUTO_UPDATE` | `true` | Schedule `registry:auto-update`. |
| `REGISTRY_AUTO_UPDATE_SCHEDULE` | `17 * * * *` | The cron expression it runs on. |

Each install has a policy, set on the Installed screen:

| Policy | Updates to |
| --- | --- |
| `none` (default) | Nothing. Update by hand. |
| `patch` | The newest compatible `x.y.Z`. |
| `minor` | The newest compatible `x.Y.z`. A `0.x` extension stays on its minor, because `0.x` minors may break. |

An automatic update goes through the same installer job as the Update button. A version whose automatic update failed isn't tried again until a newer one appears.

### Fleetbase Cloud

| Variable | Purpose |
| --- | --- |
| `REGISTRY_CLOUD_AUTO_REGISTER` | The API registers itself with the service on boot (`registry:init`) and every five minutes from the scheduler. |
| `REGISTRY_CLOUD_PLATFORM_SECRET` | Signs that registration. The service holds the same secret. |
| `REGISTRY_PREINSTALLED_EXTENSIONS` | `true` on Cloud, where the image ships every extension. |

## Artisan commands

| Command | What it does |
| --- | --- |
| `registry:register` | Register this instance with a registration code. |
| `registry:install <extension>` | Queue an install. Takes a public id, slug or package name, with `--release=` or `name@version`. |
| `registry:update <extension>` | Queue an update to the current release, or to `--release=`. |
| `registry:uninstall <extension>` | Queue a removal. |
| `registry:job <id>` | Show an installer job's status. |
| `registry:auto-update` | Queue the updates each install's policy allows. `--dry-run` shows them without queueing. |
| `registry:compat` | Check installed extensions against a target Fleetbase version. Exits 0 when nothing is blocked, 1 when something is, 2 when the check couldn't run. |
| `registry:maintenance on\|off\|status` | Turn the freeze on installs and purchases on or off. |
| `registry:init` | Run on boot. On Cloud it registers the instance. |
| `registry:cloud-register` | Register this API as the cloud instance. |
| `registry:cloud-sync-installs` | Record locally what each cloud organization has installed. |

The install, update and uninstall commands take `--company=` on an instance with more than one organization, `--sync` to run in the current process and `--json` for the job record.

```bash
php artisan registry:install pallet@0.0.2
```

```bash
php artisan registry:compat --console=0.9.0 --api=2.0.5
```

## Development

Install both sets of dependencies:

```bash
pnpm install
```

```bash
composer install
```

To work on the bridge inside a running Fleetbase, link it from the Fleetbase repository root:

```bash
flb-package-linker enable registry-bridge
```

```bash
flb-package-linker install registry-bridge
```

The Console and API then use your checkout instead of the published packages. The [development setup guide](https://www.fleetbase.io/docs/platform/quickstart/development-setup) covers package linking, Docker and Octane reloads.

Build the engine, and lint each side:

```bash
pnpm build
```

```bash
pnpm lint
```

```bash
composer test:lint
```

## Testing

Composer installs into `server_vendor`, but Pest only looks for `vendor/autoload.php`. Link the two once (the link isn't committed):

```bash
ln -sfn server_vendor vendor
```

Run the backend suite:

```bash
composer test:unit
```

Run it with coverage (needs Xdebug or PCOV). The second command writes `coverage/clover.xml`, which CI uploads to [Codecov](https://codecov.io/gh/fleetbase/registry-bridge):

```bash
XDEBUG_MODE=coverage composer test:coverage
```

```bash
XDEBUG_MODE=coverage composer test:coverage:clover
```

Run static analysis:

```bash
composer test:types
```

Run the Ember test suite:

```bash
pnpm test:ember
```

## Documentation

- [Extension development](https://www.fleetbase.io/docs/extension-development)
- [Extending Fleetbase](https://www.fleetbase.io/docs/extending-fleetbase)
- [Fleetbase CLI](https://www.fleetbase.io/docs/cli)
- [Registry Service](https://github.com/fleetbase/registry), for the catalog, publishing and the instance API
- [Fleetbase docs](https://www.fleetbase.io/docs)

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

Useful project files:

- [package.json](package.json) for frontend scripts and package metadata.
- [composer.json](composer.json) for backend scripts and package metadata.
- [extension.json](extension.json) for Fleetbase extension metadata.
- [RELEASE.md](RELEASE.md) for the notes of the current release.

## License

Registry Bridge is open-source software licensed under the [GNU Affero General Public License v3.0 or later](LICENSE.md).

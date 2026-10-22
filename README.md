<p align="center">
    <p align="center">
        <img src="https://user-images.githubusercontent.com/58805033/191936702-fed04b0f-7966-4041-96d0-95e27bf98248.png" width="280" height="80" style="height: 80px;" />
    </p>
    <p align="center">
        Internal Bridge between Fleetbase API and Extensions Registry
    </p>
</p>

---

## Overview

This monorepo contains both the frontend and backend components of the Internal Registry Bridge for Fleetbase. The frontend is built using Ember.js and the backend is implemented in PHP.

* PHP 7.3.0 or above
* Ember.js v4.8 or above
* Ember CLI v4.8 or above
* Node.js v18 or above

## Structure

```
├── addon
├── app
├── assets
├── translations
├── config
├── node_modules
├── server
│ ├── config
│ ├── data
│ ├── migrations
│ ├── resources
│ ├── src
│ ├── tests
│ └── vendor
├── tests
├── testem.js
├── index.js
├── package.json
├── phpstan.neon.dist
├── phpunit.xml.dist
├── pnpm-lock.yaml
├── ember-cli-build.js
├── composer.json
├── CONTRIBUTING.md
├── LICENSE.md
├── README.md
```

## Installation

### Backend

Install the PHP packages using Composer:

```bash
composer require fleetbase/core-api
composer require fleetbase/registry-bridge
```
### Frontend

Install the Ember.js Engine/Addon:

```bash
pnpm install @fleetbase/registry-bridge-engine
```

## Registry Service

With a Registry Service URL configured, Explore, Installed, installs and update checks go through the
[Fleetbase Registry Service](https://github.com/fleetbase/registry) (API `v2`). Without one, Explore falls back to
the cloud catalog at `REGISTRY_HOST` (`~registry/v1/extensions`), read-only.

| Setting (env) | Purpose |
| --- | --- |
| `REGISTRY_SERVICE_URL` | Service base URL, for example `https://registry.fleetbase.io`. Also editable on the admin Registry Configuration page |
| `REGISTRY_API_VERSION` | `v2` (default when a service URL is set) or `v1` to keep the cloud catalog fallback |
| `REGISTRY_INSTANCE_KIND` | `self_hosted` (default) or `cloud`; reported at registration and matched against each extension's hosting |
| `REGISTRY_INSTANCE_TOKEN` | Instance token, normally issued by registering from the admin page and stored encrypted in settings instead |

Register the instance on the admin Registry Configuration page with a one-time code from an owner or admin of your
registry organization (`POST /v1/account/instance-codes` on the service). The instance token is stored encrypted with
the app key and only ever shown masked.

Installs of service extensions run through the queued installer (see Extension installer below), which looks them up
by `ext_` id, slug or package name. Its resolve step reports `installing` to the service's instance API, which checks
the licence and the extension's hosting before anything is installed; `installed`, `failed` and uninstalls are reported
when the job ends. Composer and pnpm authenticate per process: `COMPOSER_AUTH` for Composer, and for npm
`NPM_CONFIG_//<host>/npm/:_authToken` plus `npm_config_userconfig` pointing at
[`server/resources/npm/registry.npmrc`](server/resources/npm/registry.npmrc), which holds only `${…}` placeholders
(pnpm 11 does not read `//host` credentials from the environment). No `auth.json` or `.npmrc` with a token is
written. The installer adds the service's Composer repository to the API's `composer.json` and adds engines by
tarball URL, so an engine's npm scope is never remapped.

## Usage

### Backend

🧹 Keep a modern codebase with **PHP CS Fixer**:
```bash
composer lint
```

⚗️ Run static analysis using **PHPStan**:
```bash
composer test:types
```

✅ Run unit tests using **PEST**
```bash
composer test:unit
```

🚀 Run the entire test suite:
```bash
composer test
```

### Frontend

🧹 Keep a modern codebase with **ESLint**:
```bash
pnpm lint
```

✅ Run unit tests using **Ember/QUnit**
```bash
pnpm test
pnpm test:ember
pnpm test:ember-compatibility
```

🚀 Start the Ember Addon/Engine
```bash
pnpm start
```

🔨 Build the Ember Addon/Engine
```bash
pnpm build
```

## Extension installer

Installs, uninstalls and updates run as `InstallExtensionJob` on the `installer` queue, one at a time per instance (a cache lock owned by the running job). The console's `POST installer/install` and `installer/uninstall` return `202 { status: "queued", job }`; poll `GET installer/jobs/{id}` or listen on `install.{company}.{extension}` (`uninstall.…` for uninstalls) for `step` and `progress`. The console's install and uninstall modals do both (`addon/utils/installer-job.js`): they show the queue position and each step, count down to a reload on success, and on failure show the step, message and log tail with Retry.

```bash
php artisan registry:install pallet --release=0.0.2   # or pallet@0.0.2; artisan reserves --version
php artisan registry:update pallet --json
php artisan registry:uninstall pallet --company=<uuid or public id>
php artisan queue:work --queue=installer --timeout=7200   # the worker that runs them
```

`--sync` runs the job in the current process. Stages: resolve the release, download and verify checksums, snapshot `composer.json`, `composer.lock`, the console's `package.json` and `pnpm-lock.yaml`, the runtime manifest and the engine's `engines-dist` folder, `composer require`, the engine step, clear caches, record the install. Any failure restores the snapshot and reports the stage and the last 50 log lines.

When a release carries a prebuilt engine dist (D3 Outcome A), the engine step checks it against the console's `dist/host-modules.json` with `@fleetbase/ember-core`'s compatibility check, unpacks it into the runtime engines directory (default `<console>/dist/engines-dist`) and adds it to the manifest served at `GET ~registry/v1/engines/runtime`, which the console reads at boot. Without a prebuilt dist it falls back to `pnpm add` and `pnpm build`. Settings live under `registry-bridge.installer` in `server/config/registry-bridge.php`.

Where releases come from is a `ReleaseSource`: with a Registry Service configured the service catalog is asked first and this instance's registry tables second (`SourceRoutingReleaseSource`). The job record's `extension.source` (`registry-service` or `local`) decides where the install is recorded and whether the service hears about it (`InstallReporter`); credentials reach Composer and pnpm through `PackageCredentials`, as process environment only.

## Contributing
See the Contributing Guide for details on how to contribute to this project.

## License
This project is licensed under the MIT License.

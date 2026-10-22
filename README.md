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

Installs, uninstalls and updates run as `InstallExtensionJob` on the `installer` queue, one at a time per instance (a cache lock owned by the running job). The console's `POST installer/install` and `installer/uninstall` return `202 { status: "queued", job }`; poll `GET installer/jobs/{id}` or listen on `install.{company}.{extension}` (`uninstall.…` for uninstalls) for `step` and `progress`.

```bash
php artisan registry:install pallet --release=0.0.2   # or pallet@0.0.2; artisan reserves --version
php artisan registry:update pallet --json
php artisan registry:uninstall pallet --company=<uuid or public id>
php artisan queue:work --queue=installer --timeout=7200   # the worker that runs them
```

`--sync` runs the job in the current process. Stages: resolve the release, download and verify checksums, snapshot `composer.json`, `composer.lock`, the console's `package.json` and `pnpm-lock.yaml`, the runtime manifest and the engine's `engines-dist` folder, `composer require`, the engine step, clear caches, record the install. Any failure restores the snapshot and reports the stage and the last 50 log lines.

When a release carries a prebuilt engine dist (D3 Outcome A), the engine step checks it against the console's `dist/host-modules.json` with `@fleetbase/ember-core`'s compatibility check, unpacks it into the runtime engines directory (default `<console>/dist/engines-dist`) and adds it to the manifest served at `GET ~registry/v1/engines/runtime`, which the console reads at boot. Without a prebuilt dist it falls back to `pnpm add` and `pnpm build`. Settings live under `registry-bridge.installer` in `server/config/registry-bridge.php`.

## Contributing
See the Contributing Guide for details on how to contribute to this project.

## License
This project is licensed under the MIT License.

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

Installs report to the service's instance API, which checks the licence and the extension's hosting before anything
is installed. Composer and pnpm authenticate per process: `COMPOSER_AUTH` for Composer, and for npm
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

## Contributing
See the Contributing Guide for details on how to contribute to this project.

## License
This project is licensed under the MIT License.

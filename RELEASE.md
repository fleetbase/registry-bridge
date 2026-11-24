> v0.3.0 ~ "Extensions Registry v2: the Registry Service client, installs without a console rebuild, and the redesigned Extensions screens"

---
## Highlights

- **Registry Service (v2) client.** The bridge now talks only to the Fleetbase Registry Service (`REGISTRY_SERVICE_URL`, default `https://registry.fleetbase.io`) for the catalog, purchases, licences and package downloads. The v1 registry code (Verdaccio-backed endpoints, registry users and bundles) is removed. ([#44](https://github.com/fleetbase/registry-bridge/pull/44), [#49](https://github.com/fleetbase/registry-bridge/pull/49))
- **Instance registration.**
  - `registry:init` (in the API's start command) and `registry:register` register a self-hosted instance with a code from the registry website.
  - The Fleetbase Cloud registers itself as the platform instance (`REGISTRY_CLOUD_AUTO_REGISTER`, `REGISTRY_CLOUD_PLATFORM_SECRET`, `registry:cloud-register`).
  - Installs are reported per company (`registry:cloud-sync-installs`). ([#52](https://github.com/fleetbase/registry-bridge/pull/52))
- **Queued installer.**
  - Installs, updates and uninstalls run as jobs on the `installer` queue, and the console follows them over the socket (polling as a fallback).
  - A release's prebuilt engine is mounted as a **runtime engine**, so the console isn't rebuilt (needs ember-core 0.4.0 and console v0.9.0).
  - Composer packages go into the extensions manifest (`api/extensions`).
  - A release that has no prebuilt engine, on a setup that can't build the console, is refused up front with a clear reason.
  - The CLI's `flb` uses `registry:job`, `registry:install`, `registry:update` and `registry:uninstall`. ([#45](https://github.com/fleetbase/registry-bridge/pull/45), [#46](https://github.com/fleetbase/registry-bridge/pull/46), [#51](https://github.com/fleetbase/registry-bridge/pull/51), [#57](https://github.com/fleetbase/registry-bridge/pull/57))
- **Auto-update.** Each installed extension has an update policy. `registry:auto-update` runs on the scheduler (`REGISTRY_AUTO_UPDATE_SCHEDULE`, hourly by default) inside the windows the registry allows. `registry:compat` checks a planned Fleetbase upgrade against the installed extensions. ([#61](https://github.com/fleetbase/registry-bridge/pull/61))
- **Redesigned Extensions screens.**
  - Discover, with featured listings and a category and filter rail.
  - Category pages with a card or list view.
  - An extension page that installs in place (no modal), with highlights, setup steps, screenshots, what's new and a plain compatibility verdict.
  - A library (Installed, Purchased, Updates) with in-place removal, auto-update switches, and "find it" links that point at where an installed extension lives in the console.
  - Works on phones, in light and dark.
- **Cutover freeze.** `REGISTRY_MAINTENANCE` / `registry:maintenance` pauses installs and purchases in the console while the cloud moves to the Registry Service.

---
## Upgrading

- **Breaking:**
  - this release needs the Registry Service (registry v2.0.0) and Fleetbase **v0.9.0** (core-api 2.0, ember-core 0.4.0, the console's runtime-engine initializers);
  - the v1 registry tables are no longer used;
  - consoles whose `.npmrc` still points the `@fleetbase` scope at `registry.fleetbase.io` must remove it: the Registry Service only serves extension packages, not the core `@fleetbase/*` packages from npmjs.
- Run `php artisan migrate`. That creates `registry_service_installs`, adds the auto-update columns, and makes `uuid` unique on the older registry tables (from v0.2.1).
- The API needs a worker on the `installer` queue. In the Docker images it runs next to Octane with `REGISTRY_INSTALLER_WORKER=true` and `REGISTRY_INSTALLER_RELOAD_OCTANE=true`. The scheduler must run for auto-update and cloud re-registration.
- Self-hosted: register the instance in the admin settings (Registry Service → Instance registration) with a one-time code from an owner or admin of your registry organization, or run `php artisan registry:register`.
- Fleetbase Cloud: set `REGISTRY_CLOUD_AUTO_REGISTER=true` and `REGISTRY_CLOUD_PLATFORM_SECRET` (the same value as the Registry Service). Set `REGISTRY_PREINSTALLED_EXTENSIONS=true` where every extension is already in the images.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---

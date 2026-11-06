> v0.2.0 ~ "Laravel 12 and core-api 2.0"

---
## Highlights

- **Laravel 12.** The server package now runs on Laravel 12 with Carbon 3 and requires `fleetbase/core-api` ^2.0. It installs into the Laravel 12 Fleetbase API (fleetbase/fleetbase v0.8.0); Laravel 10 installs stay on the v0.1.11 line.
- **No security advisory exceptions.** `composer audit` reports no advisories on the Laravel 12 dependency set, so the Composer advisory workarounds are gone.

---
## Upgrading

- **Requires fleetbase/core-api v2.0.0** (Laravel 12, PHP 8.2 or later). Upgrade the API to fleetbase/fleetbase v0.8.0 first, then this extension.
- Run `php artisan migrate` after upgrading.

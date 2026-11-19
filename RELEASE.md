> v0.2.1 ~ "Foreign keys point at unique keys"

---
## Highlights

- **Registry tables that foreign keys point at can be altered on MySQL 8.4 with `restrict_fk_on_non_standard_key` on.** MySQL 8.4 refuses any change to a table that a foreign key points at through a non-unique key, with `1553 Cannot drop index '<unknown key name>'`. `uuid` is now unique on `registry_extensions`, `registry_extension_bundles` and `registry_users`. Each column's old index is replaced, so it is not indexed twice. A column that already has a unique or primary key is left alone. A table with duplicate values is skipped and reported, and the migration can be run again once they are removed.

---
## Upgrading

- Run `php artisan migrate`. If a table is reported as having duplicate values, it is skipped. Remove the duplicates, then run the `php artisan migrate:refresh --force --path=…` command the message gives. It rolls back and re-runs only that migration, and lists every other migration as "Migration not found" without touching them.
- No new dependency: the migration doesn't need core-api 2.0.2's migration hook.


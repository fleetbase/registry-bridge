> v0.3.1 ~ "Artisan runs on an instance without an app key"

---
## Highlights

- **Artisan commands no longer fail at boot when `APP_KEY` is not set.** Every boot applies the registry settings stored in the `settings` table. 0.3.0 resolved Laravel's encrypter for that even when no instance token was stored. On an instance without an app key, every artisan command failed with "No application encryption key has been specified" once the settings table existed. That included `php artisan key:generate`, the command that sets the key, and `migrate:status` in CI.

  The encrypter is now resolved only when a token is stored. A token that can't be decrypted (no key, or a changed key) is treated as absent, so the instance shows as not registered until it registers again.

---
## Upgrading

No changes needed.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---

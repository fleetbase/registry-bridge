> v0.1.11 ~ "Extensions load in console test mode"

---
## Highlights

- **Fix: registry endpoints returned 500 in console test mode.** `~registry/v1/engines`, `registry-extensions`, `installed`, `purchased` and `categories` queried the sandbox database, where registry tables don't exist. Registry models and the category queries now always use the live connection. (#41)

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)

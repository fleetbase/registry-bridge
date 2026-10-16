> v0.1.11 ~ "Extensions load in console test mode"

---
## Highlights

- **Fix: registry endpoints returned 500 in console test mode.** `~registry/v1/engines`, `registry-extensions`, `installed`, `purchased` and `categories` queried the sandbox database, where registry tables don't exist. Registry models and the category queries now always use the live connection. (#41)

- **Fix: the Purchased extensions page showed blank cards.** `ExtensionCard` was given `@extension` but reads `@resource`, so every purchased extension rendered without a name, icon, price or date, and no card on any page showed its description. (#40)

---
## Upgrading
- No migrations or commands. The console engine now depends on fleetbase/ember-ui v0.4.6 and fleetbase/ember-core v0.3.26. (#43)

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)

# Changelog

## Unreleased

The first version.

### Added

- **A compiled cache store for the Stache.** Entries become rows in PHP files (one set per collection), indexes become one PHP file each, and OPcache serves them from shared memory. Files are content-addressed and never rewritten in place; `pointer.json` says which are current. Writes are buffered for the request and written once, under a lock, merged with other writers.
- **Large fields on demand.** Top-level fields over 2 KB of YAML (page builders, long Bard fields) aren't parsed until something reads them, so listings, URIs, navs and queries skip them.
- **Faster parsing.** Directory scans with `scandir`, and a strict fast path for plain YAML that falls back to Symfony YAML for anything else.
- **Commands.** `flatpack:build` (everything, with large fields pre-parsed in parallel where pcntl is available), `flatpack:refresh` (only what changed on disk, including deleted files and tree changes Statamic's own watcher misses) and `flatpack:status`.
- **A Control Panel utility.** Utilities → Flatpack shows what's compiled and what's changed on disk since, and builds or refreshes from the Control Panel. Statamic's own Warm button records what Flatpack needs to track changes.
- **A parity suite** (`bin/parity`) that compares Flatpack with plain Statamic on a fresh app at any version, run in CI on Statamic 5 and 6.
- **Several servers.** `flatpack:status` and the Flatpack page say when Flatpack has replaced a Stache that would have been in a shared cache. `FLATPACK_CP_ACTIONS=false` hides the Build and Refresh buttons, and they never run on a queue (a worker can be another machine). Docs for Laravel Cloud (build commands, not deploy commands) and several servers.
- **Safe to switch off.** With `FLATPACK_ENABLED=false`, `flatpack:build` and `flatpack:refresh` warm Statamic's own Stache instead, so deploy scripts don't change, and Statamic's own Warm is left alone.
- **Works on Statamic 5 and 6**, with no PHP extensions beyond what Laravel Forge and Laravel Cloud ship.

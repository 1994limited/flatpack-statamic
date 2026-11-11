# Changelog

## 1.2.1 (2026-11-11)

### Changed

- **Saving an entry does less work afterwards.** Statamic puts every index of a collection back into the Stache on each save, and most of them come back exactly as they were read. Flatpack used to export each one to PHP again before noticing the file already existed. It now compares first and skips the ones that haven't changed. On the demo's 9,000-entry site, the request that saves a book went from about 165 ms to about 130 ms.

## 1.2.0 (2026-11-10)

### Changed

- **Faster builds, with less memory.** Statamic builds each entry's URL by running its route through Antlers with a new parser every time. Routes that are only placeholders (`/books/{slug}`, `{parent_uri}/{slug}`) now have their values put in directly; anything else still goes through Antlers. On the demo's 9,000-entry nested page builder site the build went from 17.0 s to 12.8 s, with about a quarter less memory.
- **Entries are cheaper to read.** Statamic snapshots an entry's state every time one is read, so it can later say what changed. Flatpack now works that snapshot out only when something asks for it. Reading 9,000 entries through a query went from 81 to 32 microseconds each.

## 1.1.1 (2026-11-10)

### Fixed

- **Building after a deploy into a new release directory.** With zero-downtime deploys the app moves to a new path while `storage` is shared. `flatpack:build` then failed, and left the site with no content until it was fixed. It now builds from the new path, and a build that fails for any reason leaves the last good one in place.
- `flatpack:refresh` (and Refresh in the Control Panel) builds everything when the content was compiled for another path, where before it failed. `flatpack:status` says so too.

### Changed

- **Statamic's own fast YAML parser is used where it has one.** Flatpack's copy is only used on Statamic versions without it.

## 1.1.0 (2026-11-10)

### Changed

- **A faster YAML parser that gives up on far fewer files.** An unusual key or value (a double-quoted string with escapes, a key that starts with a digit or has a dash or quotes, a date) is now read by Symfony's inline parser on its own, where before the whole file went back to Symfony's full parser. Tabs inside literal blocks no longer send the file back either. On a large site, files that needed the full parser went from 38% to 2%, and parsing all of its content from 13.9 s to 1.6 s.

### Fixed

- A sequence written inside a sequence item (`- - one`) was read as the text `- one`.
- Results now match Symfony's in the cases where its versions differ: an empty literal block, an empty value followed by a blank line, and a literal block near the end of front matter.

### Added

- `tests/yaml/fuzz.php` checks the parser against Symfony's with random and mutated documents. The parity suite runs it on every Statamic version it tests.

## 1.0.0 (2026-11-06)

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
- **Safe across Statamic updates.** Flatpack checks at boot that the Statamic internals it relies on are still there. If a Statamic release has changed one, Flatpack pauses (the site runs on Statamic's own Stache, build and refresh fall back to Statamic's warm) and says why in `flatpack:status`, the utility and the log. The parity suite runs daily, including on Statamic's unreleased `6.x` branch.
- **Works on Statamic 5 and 6**, with no PHP extensions beyond what Laravel Forge and Laravel Cloud ship.

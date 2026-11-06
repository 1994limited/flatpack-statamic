# Testing

Flatpack is tested by comparing it with plain Statamic, which is treated as always right.

```bash
bin/parity 6.40.0           # or ^6.0, ^5.0, 5.73.0, ...
```

This creates a fresh Statamic app at that version in a temporary folder, installs Flatpack from this checkout, and generates the same test content into two copies of it (`tests/parity/generate.php`: three sites, a structured multisite pages collection with large page builders, a dated blog with localized tags, a team collection, categories, a nav and globals). One copy runs with `FLATPACK_ENABLED=false`, the other with Flatpack. Then:

| Check | What has to match |
|---|---|
| Cold and warm reads | Every entry (collection, site, origin, slug, URI, URL, status, date, blueprint, parent, order, fields and values), about 140 queries (where, whereIn, orderBy, limit, offset, paginate, pluck, taxonomy, findByUri, find), every collection tree and nav, and terms with their entries |
| Saves through the API | Eleven changes (title, page builder, unpublish, date, terms, a slug rename of a page with children, a tree move, create, localize, two deletes): same outcomes, and the same files written byte for byte (ignoring `updated_at` and `updated_by`), then the same reads |
| Rebuild | Everything compiled again from the saved files |
| Switched off | With `FLATPACK_ENABLED=false`, `stache:warm`, `flatpack:build` and `flatpack:refresh` all succeed |
| Files changed on disk | Hand edits, a deleted file, a new file, a renamed file and a reordered page tree, then `flatpack:refresh`, against plain Statamic rebuilt from scratch |
| Statamic changed something Flatpack relies on | A Statamic property Flatpack uses is renamed throughout the app's copy of Statamic (`tests/parity/paused.sh`, put back afterwards): Flatpack pauses, `flatpack:status` says so, build and refresh still succeed, and reads match plain Statamic |

Options: `PHP_BIN` for another PHP, `KEEP=1` to reuse the app from the last run (and switch its Statamic version if a different exact version is given), `PARITY_ENTRIES` for more or less content.

The GitHub Actions workflow runs it on the earliest supported Statamic 5, the latest 5 and the latest 6, on every push and daily. Daily, it also runs on Statamic's unreleased `6.x` branch, to catch a change before Statamic tags it (not on pull requests, where it would fail them for changes outside this repo).

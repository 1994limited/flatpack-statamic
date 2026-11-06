# Commands

## `php please flatpack:build`

Compiles all content from your files. Run it at deploy. Removes compiled files that are no longer used.

With Flatpack switched off (`FLATPACK_ENABLED=false`), it clears and warms Statamic's own Stache instead, so a deploy script with only `flatpack:build` in it works either way.

| Option | |
|---|---|
| `--workers=N` | Parallel workers for pre-parsing large fields. `0` skips it. Defaults to `build_workers` in the config. |

## `php please flatpack:refresh`

Updates the compiled content with files changed on disk since the last build or refresh: changed, added, renamed and deleted entries, terms, globals, navs and page trees. If nothing has been built yet, it builds.

With Flatpack switched off, it rebuilds Statamic's own Stache (Statamic has no "only what changed" refresh to fall back to), so the result is right, just slower.

## `php please flatpack:status`

Shows whether Flatpack is on, where it writes, what's been built, and whether OPcache and pcntl are available.

## Statamic's own commands

Statamic's Stache commands keep working, and go through Flatpack. Watch the names: Statamic's `stache:refresh` and Flatpack's `flatpack:refresh` do opposite things.

| Command | With Flatpack installed |
|---|---|
| `php please stache:refresh` | A **full rebuild**: clears everything, then compiles everything. It doesn't pre-parse large fields, so the first visit to a page with a big page builder parses it then. |
| `php please stache:warm` | Compiles everything, without clearing first. Same note about large fields. |
| `php please stache:clear` | Empties Flatpack. The next request, or a build, fills it again. |
| `php please flatpack:build` | A full rebuild, **plus** the large-field pre-parse and removing old files. Use this one in your deploy. |
| `php please flatpack:refresh` | **Not** a rebuild: re-reads only the files that changed on disk since the last build or refresh. |

In a deploy, use `flatpack:build` **instead of** `stache:clear`, `stache:warm` or `stache:refresh`, not as well as: it already does what they do, and running both compiles everything twice. Left as they are, the old commands still work (they go through Flatpack), you just miss the large-field pre-parse. See [deployment](deployment.md).

After any of Statamic's warms, Flatpack records what it needs to track later changes, so `flatpack:refresh` and the changed-files list in the Control Panel keep working. `flatpack:status` and the utility show which command did the last build.

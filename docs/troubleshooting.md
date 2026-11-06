# Troubleshooting

**Content I changed on the server doesn't show.** Run `php please flatpack:refresh`. Saves in the Control Panel update Flatpack by themselves; changes made directly to files need a refresh (or Statamic's watcher, if it's on).

**I want to compare against plain Statamic.** Set `FLATPACK_ENABLED=false`. Statamic goes back to its own Stache cache, and `php please flatpack:build` (or `stache:warm`) fills it.

**Flatpack says it's paused.** A Statamic update has renamed or removed something Flatpack reaches inside of (the message names it). Rather than risk stale or wrong content, Flatpack has switched itself off: the site runs on Statamic's own Stache, exactly as without Flatpack, just without the speed-up. `flatpack:build` and `flatpack:refresh` warm Statamic's own Stache instead, so deploys keep working. Update Flatpack to a version that supports your Statamic, or stay on the previous Statamic until there is one. Once both match, Flatpack turns itself back on; run `php please flatpack:build` to rebuild it. The warning is logged once a day while paused. See [How it stays safe across Statamic updates](how-it-works.md#how-it-stays-safe-across-statamic-updates).

**Something looks wrong after an upgrade.** Delete the compiled folder (`storage/statamic/flatpack` by default) and run `php please flatpack:build`. It's a cache: nothing is lost.

**The first visit to a page is slower than the rest.** Large fields are parsed on first read if the build didn't pre-parse them. Make sure `build_workers` isn't `0`.

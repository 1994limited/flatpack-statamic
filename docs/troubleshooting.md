# Troubleshooting

**Content I changed on the server doesn't show.** Run `php please flatpack:refresh`. Saves in the Control Panel update Flatpack by themselves; changes made directly to files need a refresh (or Statamic's watcher, if it's on).

**I want to compare against plain Statamic.** Set `FLATPACK_ENABLED=false`. Statamic goes back to its own Stache cache, and `php please flatpack:build` (or `stache:warm`) fills it.

**Something looks wrong after an upgrade.** Delete the compiled folder (`storage/statamic/flatpack` by default) and run `php please flatpack:build`. It's a cache: nothing is lost.

**The first visit to a page is slower than the rest.** Large fields are parsed on first read if the build didn't pre-parse them. Make sure `build_workers` isn't `0`.

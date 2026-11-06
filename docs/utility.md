# The Control Panel utility

Flatpack adds a page under **Utilities → Flatpack** (users need the "Flatpack" utility permission, like any utility).

![The Flatpack page in the Control Panel](https://raw.githubusercontent.com/1994limited/statamic-marketplace/main/flatpack/charts/cp-flatpack.png)

| Section | What it shows |
|---|---|
| Compiled content | When it was last built and refreshed, how many files, how much space, and where. |
| Changed since the last build | Content files that have changed on disk since the last build or refresh, for example after a `git pull`. |
| Last run | The last build or refresh started from this page: when, how long it took, and any error. |
| Server | Whether OPcache is on for web requests, and whether pcntl is available. |

**Refresh** updates the compiled content with the changed files. **Rebuild** compiles everything again.

Both run straight away, on the server that handles the click, never on a queue: a queue worker can be another machine with its own disk, so the build would land in the wrong place. A build started from the Control Panel never runs in parallel either (a web request mustn't fork), so its large-field pre-parse runs in one process and can take longer than `php please flatpack:build`.

On a site with more than one server, a click only updates one of them. Set `FLATPACK_CP_ACTIONS=false` to hide the buttons and keep the page as status only, and build in your deploy. If Flatpack has replaced a Stache that would have been in a shared cache, the page says so at the top. See [more than one server](deployment.md#more-than-one-server).

## Statamic's Cache Manager

**Utilities → Cache → Content Stache → Warm** fills Flatpack too, and Flatpack records the file list it needs to track changes afterwards. A warm doesn't pre-parse large fields, so the first visit to a page with a big page builder parses it then. **Rebuild** on the Flatpack page does both.

**Clear** empties Flatpack. The next request, or a build, fills it again.

The Flatpack page shows "by a Stache warm" next to **Last built** when the last full build came from Warm or `stache:refresh` rather than `flatpack:build`.

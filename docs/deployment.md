# Deployment

## The short version

Run `php please flatpack:build` once per deploy, after Composer has installed and before the new release takes traffic. It replaces the Stache commands you probably have in your deploy script now:

| If your deploy script has | Do this |
|---|---|
| `php please stache:clear` | Remove it. `flatpack:build` clears first. |
| `php please stache:warm` | Replace it with `php please flatpack:build`. |
| `php please stache:refresh` | Replace it with `php please flatpack:build`. |
| `php artisan statamic:stache:warm` (the same command, called through Artisan) | Replace it with `php artisan flatpack:build` (or `php please flatpack:build`: both work). |

Keep only one of them. `flatpack:build` already clears and warms the Stache, so running `stache:warm` as well compiles everything twice, which on a big site is the slowest step of the deploy.

Why `flatpack:build` rather than the Stache commands? They all fill Flatpack, because Flatpack sits underneath the Stache. But only `flatpack:build` also pre-parses large fields (page builders, long Bard fields), so the first visit to each page is as fast as the rest. After `stache:warm`, each changed large field is parsed on its first visit instead. See [Statamic's own commands](commands.md#statamics-own-commands).

## What stays in your deploy script

Flatpack only replaces the Stache steps. Everything else stays as it is:

| Step | With Flatpack |
|---|---|
| `composer install` | Before the build. |
| `php artisan config:cache`, `route:cache`, `view:cache` | Before the build, so the build uses your cached config. |
| `php artisan cache:clear` | Fine either way. It clears your application cache, not Flatpack's files. |
| `php please search:update --all` | After the build: it reads content, so it's faster with Flatpack already built. |
| `php please static:clear` and `static:warm` | After the build: warming visits your pages, which then read compiled content. |

## Example: Laravel Forge

A typical Statamic deploy script, with the Stache step swapped:

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache

$FORGE_PHP please flatpack:build          # was: $FORGE_PHP artisan statamic:stache:warm

$FORGE_PHP please search:update --all
$FORGE_PHP please static:clear
$FORGE_PHP please static:warm --queue
```

On a Forge site with several servers behind a load balancer, the deploy script runs on each server, so each builds its own copy. See [more than one server](#more-than-one-server).

With zero-downtime deployments, `storage` is shared between releases, so the build replaces the compiled files for every release at once. Files are content-addressed and never rewritten in place, so a request still running on the old release never reads a half-written file.

If you'd rather keep a separate build per release, set `FLATPACK_PATH` to a folder inside the release (for example `bootstrap/cache/flatpack`).

## Example: Laravel Cloud

On Laravel Cloud, `flatpack:build` goes in the environment's **build commands**, not its deploy commands:

```bash
composer install --no-dev
php artisan config:cache
php please flatpack:build
```

This matters. Cloud keeps what build commands write and ships it to every replica, so each one (including new ones from autoscaling, or waking up from scale to zero) starts with the same finished build. It throws away what deploy commands write, so a `flatpack:build` there would be lost, and each replica would compile on its first requests instead: the slow cold start Flatpack is there to remove. Keep `stache:refresh` and `stache:warm` out of the deploy commands too.

The build reads your content files and writes to local disk. It doesn't need your Cloud cache or database.

**Edit content locally.** Cloud's filesystem is reset on every deploy and each replica has its own, so Laravel's own advice for flat-file Statamic on Cloud is to edit content locally, commit it, and deploy. That is exactly how Flatpack works best. A save in the production Control Panel would only reach the replica that took it, and would be lost at the next deploy (that's true without Flatpack too). Set `FLATPACK_CP_ACTIONS=false`, so the Flatpack page shows status without Build and Refresh buttons. If your team needs to edit in production, store content in a database with Statamic's Eloquent driver instead; Flatpack is for flat files.

**Disk space.** Cloud gives each replica 512 MB of disk per 1 GB of RAM. Flatpack's compiled files take about 1.3 times the space of your content's YAML (`php please flatpack:status` shows the size), plus the rest of your app. On the smallest instances, check it fits.

## Switching Flatpack off

You don't need to change the deploy script. With `FLATPACK_ENABLED=false`, Statamic goes back to its own Stache cache, and `flatpack:build` does what your deploy did before Flatpack: it clears and warms Statamic's own Stache. So you can switch Flatpack off (to compare, or to rule it out while debugging) and on again with the environment variable alone.

## More than one server

Flatpack keeps the compiled content on each server's own disk, because that's where its speed comes from: PHP files OPcache serves from memory. Plain Statamic can instead keep the Stache in a cache every server shares (Redis, Valkey, a database). That difference only matters when content is **edited in the Control Panel on a live site that runs on several servers**:

| Setup | With Flatpack |
|---|---|
| One server | Works as normal, Control Panel edits included. |
| Several servers, content edited locally and deployed | Works well. Each server builds in its own deploy (Forge runs the deploy script on each server; Laravel Cloud builds once for every replica). |
| Several servers, content edited in the Control Panel | Not a good fit. A save updates the server that took it; the others catch up only when they run `flatpack:refresh` or the next deploy rebuilds. Keep the Stache in a shared cache without Flatpack, or store content in a database. |

When Flatpack replaces a Stache that would have used a shared cache, `flatpack:status` and the Flatpack page say so. On a single server that's expected: turn the notice off with `FLATPACK_WARN_SHARED_STACHE=false`.

On several servers, also set `FLATPACK_CP_ACTIONS=false`. The Build and Refresh buttons only ever update the server that handles the click, so they'd leave the others behind.

## Content changed on the server

Saves through the Control Panel keep Flatpack up to date by themselves. If something else changes content files on the server (a `git pull` outside a deploy, an import script, a sync), run:

```bash
php please flatpack:refresh
```

Don't confuse it with Statamic's `stache:refresh`, which rebuilds everything. `flatpack:refresh` only re-reads the files that changed, so it's quick enough to run after every pull.

## OPcache

Flatpack is fastest when OPcache is on for web requests, which it is on Forge and Laravel Cloud. If you set `opcache.validate_timestamps=0`, that's fine: compiled files are never rewritten in place, so OPcache can't serve a stale one, and you don't need to reload PHP-FPM for Flatpack's sake.

### If OPcache is off

Without OPcache, PHP compiles Flatpack's files again on every request, and that's a little slower than plain Statamic reading its own cache. On the demo site (6,000 books with a nested page builder), pages were 2 to 14% slower than without Flatpack. With OPcache on, they were 9 to 13% faster.

Builds stay faster and use less memory either way, because builds run on the command line, where OPcache is normally off anyway. To check, run `php please flatpack:status` or open the Flatpack utility: both show whether OPcache is on for web requests. If you can't turn it on, either keep Flatpack for its faster builds and accept slightly slower pages, or switch it off with `FLATPACK_ENABLED=false`.

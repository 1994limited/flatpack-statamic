# How it works

Statamic reads your content files into the **Stache**: one item per entry, term, global and so on, plus indexes (URIs, slugs, dates, parents, order) used to answer queries. Normally the Stache keeps all of that in Laravel's cache as serialized blobs, and a cold request has to unserialize what it needs.

Flatpack replaces the Stache's **cache store** and nothing else. Statamic still decides what to store, when to update it and how to query it, so behaviour stays the same.

## What gets written

Everything lives under `storage/statamic/flatpack` (configurable):

| Folder | What's in it |
|---|---|
| `shards/` | Entries, as plain arrays, in a fixed number of files per collection (`return [...]`) |
| `kv/` | Indexes and other plain values, one file each |
| `bodies/` | Large fields, pre-parsed (see below) |
| `fallback/` | Anything that isn't a plain value, in an ordinary file cache |
| `pointer.json` | Which file is current for each name |

Files are named by a hash of their contents and never rewritten in place. A change writes a new file and updates `pointer.json`, so a request never reads a half-written file, and OPcache never serves a stale one. Old files are removed at the next build.

Because the files are `return [...]` PHP, OPcache compiles them once and serves them from shared memory to every request after that.

## Large fields

Most of a typical site's YAML is page builders (Replicator, Bard, Grid). Most requests never read them: listings, navs, URLs, search and queries use titles, dates, slugs and relationships.

When Flatpack builds, any top-level field bigger than `lazy_limit` (2 KB by default) is left unparsed and replaced by a small placeholder. The first time something reads that field, it's parsed (from the pre-parsed copy in `bodies/` if the build made one, otherwise from the file) and kept for the rest of the request. Code that reads fields sees the same values either way.

## Writes

Saves from the Control Panel go through Statamic as normal, so the content files written are exactly the same. The compiled copy is updated in memory during the request and written once at the end, under a lock, merged with any other request writing at the same time.

## Changes made outside the Control Panel

A deploy or `git pull` changes files directly. `php please flatpack:refresh` finds which files changed, re-reads only those, and re-derives what depends on them: URIs, parents and order after a page tree changes, and the taxonomy terms linked to changed entries. It also handles deleted files, which Statamic's own watcher leaves behind.

If you change content files on a server and don't run a refresh, Statamic's own file watcher (when it's on) still picks up changed files as usual.

## How it stays safe across Statamic updates

To do its job, Flatpack reaches inside parts of Statamic that aren't public API: a few protected properties and methods of the Stache's stores, its indexes, the entry class and the file traverser. A Statamic release could rename them, and some of those changes would fail silently (writing to a renamed property just creates a new one), leaving stale content.

So Flatpack checks, every time it boots, that everything it uses is still there (`src/Compatibility.php` lists them). If anything is missing, it doesn't load: the site runs on Statamic's own Stache, `flatpack:build` and `flatpack:refresh` warm Statamic's Stache instead, and `flatpack:status`, the Flatpack utility and the log say what changed. The worst a Statamic update can do is make the site as fast as it was without Flatpack. The check costs a few `property_exists` calls on classes Statamic loads anyway.

That check only knows the names are still there, not that they still mean the same thing. That's what the [parity suite](testing.md) is for: it compares Flatpack with plain Statamic every day on the latest Statamic 5 and 6, and on Statamic's unreleased `6.x` branch, so a change is caught before it's tagged. Composer won't install Flatpack on a Statamic major it hasn't been tested on.

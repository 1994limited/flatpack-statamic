<?php

namespace NineteenNinetyFour\Flatpack;

use NineteenNinetyFour\Flatpack\Lazy\LazyBody;
use Statamic\Facades\Stache;

/** The deploy-time steps: compile everything, or refresh what changed on disk. */
final class Builder
{
    public static function store(): FlatpackStore
    {
        return Stache::cacheStore()->getStore();
    }

    /** Full build: clear, warm (lean), persist, collect garbage. Optionally precompile bodies. */
    public static function compile(int $bodyWorkers = 0): array
    {
        $t = hrtime(true);
        Stache::clear();
        Listeners\RecordManifest::$paused = true;
        try {
            Stache::warm();
        } finally {
            Listeners\RecordManifest::$paused = false;
        }
        self::recordTimestamps();
        self::store()->persist();
        $gc = self::store()->gc();
        $out = ['compile_s' => round((hrtime(true) - $t) / 1e9, 2), 'gc_files' => $gc];
        if ($bodyWorkers > 0) $out += self::precompileBodies($bodyWorkers);
        self::stamp(['built_at' => time(), 'build_s' => round((hrtime(true) - $t) / 1e9, 2), 'refreshed_at' => null, 'via' => 'flatpack:build']);

        return $out;
    }

    /** @return array<string, \Statamic\Stache\Stores\Store> every concrete store, keyed by its key */
    public static function allStores(): array
    {
        $all = [];
        foreach (Stache::stores() as $store) {
            foreach ($store instanceof \Statamic\Stache\Stores\AggregateStore ? $store->discoverStores() : [$store] as $s) $all[$s->key()] = $s;
        }

        return $all;
    }

    /**
     * The file manifest the watcher compares against. Statamic only records it while the watcher is
     * on, so a production-style warm (watcher off) leaves nothing to refresh from: record it here.
     */
    public static function recordTimestamps(): void
    {
        foreach (self::allStores() as $key => $s) {
            if (! $s->directory()) continue;
            Stache::cacheStore()->forever("stache::timestamps::{$key}", \Facades\Statamic\Stache\Traverser::filter([$s, 'getItemFilter'])->traverse($s)->all());
        }
    }

    /**
     * Content files changed on disk since the last build or refresh, by store
     * (the same comparison Statamic's watcher makes). Reads directories only.
     *
     * @return array{0: array<string, list<string>>, 1: array<string, array>, 2: array<string, array>} changed paths, old manifests, new manifests
     */
    public static function changes(?array $stores = null): array
    {
        $cache = Stache::cacheStore();
        $changed = $before = $nowAll = [];
        foreach ($stores ?? self::allStores() as $key => $s) {
            if (! $s->directory()) continue;
            $now = \Facades\Statamic\Stache\Traverser::filter([$s, 'getItemFilter'])->traverse($s)->all();
            $old = $cache->get("stache::timestamps::{$key}") ?? [];
            if ($now != $old) {
                $changed[$key] = array_keys(array_diff_assoc($now, $old) + array_diff_key($old, $now));
                $before[$key] = $old;
                $nowAll[$key] = $now;
            }
        }

        return [$changed, $before, $nowAll];
    }

    /** When the last build and refresh happened: `built.json` beside the compiled files. */
    public static function stamps(): array
    {
        $s = @file_get_contents(Flatpack::$path.'/built.json');

        return ($s ? json_decode($s, true) : null) ?: [];
    }

    /** A warm outside flatpack:build (stache:warm, stache:refresh, the Cache Manager) counts as a build. */
    public static function stampWarm(): void
    {
        self::stamp(['built_at' => time(), 'build_s' => null, 'refreshed_at' => null, 'via' => 'stache:warm']);
    }

    private static function stamp(array $values): void
    {
        Flatpack::writeAtomic(Flatpack::$path.'/built.json', json_encode($values + self::stamps()));
    }

    /** Drop a store's memoised path list. Statamic 6 has resetMemoizedState() for this; 5 doesn't. */
    private static function forgetPaths($store): void
    {
        if (method_exists($store, 'resetMemoizedState')) {
            $store->resetMemoizedState();

            return;
        }

        (fn () => $this->paths = null)->call($store);
    }

    /** Whether the file manifest refreshes compare against has been recorded. */
    public static function hasManifest(): bool
    {
        foreach (self::allStores() as $key => $s) {
            if ($s->directory() && Stache::cacheStore()->get("stache::timestamps::{$key}") !== null) return true;
        }

        return false;
    }

    /**
     * Incremental refresh after files changed on disk (git pull, deploy, hand edits). Statamic's
     * watcher re-reads changed files but never re-derives what depends on them (entry URIs,
     * parents and order after a tree file change; term associations), so after it runs, every
     * collection that had a changed entry or tree file gets its indexes rebuilt from the
     * compiled items (no YAML), then the term indexes of the taxonomies those collections use.
     */
    public static function refresh(): array
    {
        $t = hrtime(true);
        $all = self::allStores();
        $cache = Stache::cacheStore();
        [$changed, $before, $nowAll] = self::changes($all);
        if (! $changed) {
            self::stamp(['refreshed_at' => time()]);

            return ['refresh_ms' => round((hrtime(true) - $t) / 1e6, 1), 'changed_stores' => []];
        }
        // Statamic's watcher builds its deleted list as ids but then resolves them as paths, so files
        // deleted on disk are never removed (Store::handleFileChanges). Work out deletions here, before
        // the watcher is on: once on, any store lookup (even from another store) runs it.
        $gone = [];
        foreach ($changed as $key => $_) {
            $s = $all[$key];
            $byPath = $s->paths()->flip();
            foreach (array_diff_key($before[$key], $nowAll[$key]) as $path => $_t) {
                foreach ((array) $byPath->get($path) as $id) $gone[$key][] = [$path, $id];
            }
        }
        // collections to re-derive: changed entry stores + collections whose tree files changed
        $collections = [];
        foreach ($changed as $key => $paths) {
            if (str_starts_with($key, 'entries::')) $collections[substr($key, 9)] = true;
            if ($key === 'collection-trees') foreach ($paths as $p) $collections[$treeTouched[] = pathinfo($p, PATHINFO_FILENAME)] = true;
        }
        // Collections whose only change is files edited in place (nothing added, removed or renamed,
        // no tree): only the edited entries, the localizations inheriting from them and the pages below
        // them in a structure can have different index values; the rest keep theirs.
        $editedOnly = [];
        foreach (array_keys($collections) as $handle) {
            $key = "entries::{$handle}";
            if (! isset($changed[$key]) || in_array($handle, $treeTouched ?? [], true) || ! empty($gone[$key])) continue;
            if (count($before[$key]) !== count($nowAll[$key]) || array_diff_key($nowAll[$key], $before[$key])) continue;
            if (! \Statamic\Facades\Collection::findByHandle($handle)) continue;
            $editedOnly[$handle] = array_keys(array_diff_assoc($nowAll[$key], $before[$key]));
        }
        $taxonomies = [];
        foreach (array_keys($collections) as $handle) {
            if (! isset($all["entries::{$handle}"])) continue;
            foreach (\Statamic\Facades\Collection::findByHandle($handle)?->taxonomies() ?? [] as $tax) $taxonomies[$tax->handle()] = true;
        }
        // Term indexes as they were before this refresh. Term values are derived from the term files
        // and the associations index only, so when neither changed they are kept as they are.
        $termSnap = [];
        foreach (array_keys($taxonomies) as $tax) {
            if (! ($s = $all["terms::{$tax}"] ?? null) || isset($changed["terms::{$tax}"])) continue;
            $snap = [];
            foreach ($s->resolveIndexes() as $name => $ix) {
                if ($name === 'entries_count' || ($v = $cache->get($ix->cacheKey())) === null) { $snap = null; break; }
                $snap[$name] = $v;
            }
            if ($snap && isset($snap['associations'])) $termSnap[$tax] = $snap;
        }
        config(['statamic.stache.watcher' => true]);
        // trees first (entries' URIs depend on them), then everything else
        uksort($all, fn ($a, $b) => (str_contains($b, 'trees') <=> str_contains($a, 'trees')));
        foreach ($all as $key => $s) {
            if (! isset($changed[$key])) continue;
            (fn () => $this->fileChangesHandled = false)->call($s);
            $s->handleFileChanges();
            self::forgetPaths($s);
            $current = $s->paths();
            foreach ($gone[$key] ?? [] as [$path, $id]) {
                // a rename: same id, new path, already re-indexed by the watcher
                if (($p = $current->get($id)) && $p !== $path) continue;
                (function () use ($path, $id) {
                    $this->forgetItem($id);
                    $this->forgetPath($id);
                    $this->resolveIndexes()->filter->isCached()->each->forgetItem($id);
                    // not handleDeletedItem(): it rewrites the structure's tree file, and a refresh
                    // must never write content. A tree pointing at a missing entry is skipped on read.
                })->call($s);
            }
        }
        \Statamic\Facades\Blink::forget('collection-structure-tree*');
        \Statamic\Facades\Blink::forget('structure-*');
        \Statamic\Facades\Blink::store('entry-uris')->flush();

        foreach (array_keys($collections) as $handle) {
            if (! $store = $all["entries::{$handle}"] ?? null) continue;
            // the store memoised its path list before the watcher changed it: drop it, then put the
            // list in current traversal order (ties in sorted queries fall back to it, as in a rebuild)
            self::forgetPaths($store);
            (fn () => $this->keys = null)->call($store);
            $byPath = $store->paths()->flip();
            $ordered = [];
            foreach (\Facades\Statamic\Stache\Traverser::filter([$store, 'getItemFilter'])->traverse($store)->keys() as $path) {
                if (($id = $byPath->get($path)) !== null) $ordered[$id] = $path;
            }
            (fn () => $this->cachePaths(collect($ordered)))->call($store);
            if (isset($editedOnly[$handle]) && ($ids = self::affectedIds($store, $editedOnly[$handle])) !== null) {
                $entries = array_filter(array_map(fn ($id) => $store->getItem($id), $ids));
                foreach ($store->resolveIndexes() as $ix) {
                    $ix->load();
                    foreach ($entries as $e) $ix->updateItem($e);
                    // a rebuild lists items in traversal order (edited files sort last by mtime)
                    $items = $ix->items()->all();
                    $sorted = [];
                    foreach ($ordered as $id => $_) if (array_key_exists($id, $items)) $sorted[$id] = $items[$id];
                    if (count($sorted) === count($items) && array_keys($sorted) !== array_keys($items)) {
                        (fn () => $this->items = $sorted)->call($ix);
                        $ix->cache();
                    }
                }
            } else {
                $store->resolveIndexes()->each->update();
            }
            foreach (\Statamic\Facades\Collection::findByHandle($handle)?->taxonomies() ?? [] as $tax) $taxonomies[$tax->handle()] = true;
        }
        $termsKept = [];
        foreach (array_keys($taxonomies) as $tax) {
            if (! $s = $all["terms::{$tax}"] ?? null) continue;
            self::forgetPaths($s);
            $indexes = $s->resolveIndexes();
            $snap = $termSnap[$tax] ?? null;
            if ($snap && ($assoc = $indexes->get('associations'))) {
                $assoc->update();
                if (self::sameRows($assoc->items()->all(), $snap['associations'])) {
                    // same values; key order as a rebuild makes it: first association of each
                    // site::slug, then the remaining (file-only) terms in their previous order
                    $first = [];
                    foreach ($assoc->items()->all() as $row) $first[$row['site'].'::'.$row['slug']] ??= true;
                    foreach ($indexes as $name => $ix) {
                        if ($name === 'associations') continue;
                        $v = $snap[$name];
                        $re = [];
                        foreach ($first as $k => $_) if (array_key_exists($k, $v)) $re[$k] = $v[$k];
                        $v = $re + $v;
                        if ($cache->get($ix->cacheKey()) !== $v) $cache->forever($ix->cacheKey(), $v);
                    }
                    $termsKept[] = $tax;
                    continue;
                }
                $indexes = $indexes->except('associations');
            }
            $indexes->each->update();
        }
        self::store()->persist();
        self::stamp(['refreshed_at' => time()]);

        return ['refresh_ms' => round((hrtime(true) - $t) / 1e6, 1), 'changed_stores' => array_map('count', $changed), 'rederived_collections' => array_keys($collections), 'taxonomies' => array_keys($taxonomies), 'terms_kept' => $termsKept];
    }

    /** Ids of the entries at $paths plus every localization descending from them; null if unsure. */
    private static function affectedIds($store, array $paths): ?array
    {
        $byPath = $store->paths()->flip();
        $ids = [];
        foreach ($paths as $p) {
            if (($id = $byPath->get($p)) === null) return null;
            $ids[$id] = true;
        }
        // dependents: localizations (origin) and, in a structure, pages below (parent: uri)
        $children = [];
        foreach (['origin', 'parent'] as $name) {
            foreach ($store->index($name)->items()->all() as $id => $up) {
                if ($up === null) continue;
                if (! is_string($up) && ! is_int($up)) return null;
                $children[$up][] = $id;
            }
        }
        $queue = array_keys($ids);
        while ($queue) {
            foreach ($children[array_pop($queue)] ?? [] as $c) {
                if (! isset($ids[$c])) { $ids[$c] = true; $queue[] = $c; }
            }
        }

        return array_keys($ids);
    }

    /** Same association rows regardless of order (term values depend on the set, not the order). */
    private static function sameRows(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        if ($a === $b) return true;
        $a = array_map('serialize', array_values($a));
        $b = array_map('serialize', array_values($b));
        sort($a, SORT_STRING);
        sort($b, SORT_STRING);

        return $a === $b;
    }

    /** Parse every lazily-referenced body once, in forked workers, so no request ever does. */
    public static function precompileBodies(int $workers): array
    {
        $t = hrtime(true);
        $refs = [];
        foreach (glob(Flatpack::$path.'/shards/*.php') as $f) {
            foreach (include $f as $row) {
                foreach ($row['vars']['data']['__data'] ?? [] as $v) {
                    if (LazyBody::isEncoded($v) && $v['__lazy_body'][1]) $refs[$v['__lazy_body'][0]] = $v['__lazy_body'];
                }
            }
        }
        $refs = array_values(array_filter($refs, fn ($r) => ! is_file(Flatpack::bodies()->file($r[0]))));
        if ($workers <= 1 || ! function_exists('pcntl_fork') || PHP_SAPI !== 'cli') {
            // No pcntl, one worker, or a web request (forking a web worker would send the
            // response once per child): parse them in this process.
            foreach ($refs as $r) (new LazyBody(...$r))->load();

            return ['bodies_compiled' => count($refs), 'bodies_s' => round((hrtime(true) - $t) / 1e9, 2), 'workers' => 1];
        }
        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                foreach ($refs as $i => $r) if ($i % $workers === $w) (new LazyBody(...$r))->load();
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) pcntl_waitpid($pid, $status);

        return ['bodies_compiled' => count($refs), 'bodies_s' => round((hrtime(true) - $t) / 1e9, 2), 'workers' => $workers];
    }
}

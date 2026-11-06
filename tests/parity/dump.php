<?php
// Parity dump: a fingerprint of everything content-related the app can answer, for diffing
// stock Statamic (the oracle) against an alternative storage layer.
// php dump.php <appdir> <out.json> [sections=entries,queries,structures,terms]
// Env PARITY_BOOT=<file> is required after boot (to switch modes); PARITY_SITE limits sites.

use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;

[$_, $appDir, $out] = $argv;
$sections = explode(',', $argv[3] ?? 'entries,queries,structures,terms');
require "$appDir/vendor/autoload.php";
$app = require "$appDir/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($boot = getenv('PARITY_BOOT')) require $boot;

function fp($v): string { return md5(serialize(norm($v))); }
function norm($v) {
    if ($v instanceof \Illuminate\Support\Collection) $v = $v->all();
    if ($v instanceof \Carbon\CarbonInterface) return 'date:'.$v->toIso8601String();
    if (is_object($v) && method_exists($v, 'id')) return get_class($v).':'.$v->id();
    if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = norm($x); }
    return $v;
}
function eid($e) { return $e?->id(); }

$t0 = hrtime(true);
$result = ['meta' => ['statamic' => \Statamic\Statamic::version(), 'sections' => $sections]];
$sites = Site::all()->keys()->all();

if (in_array('entries', $sections)) {
    $rows = [];
    foreach (Entry::all()->sortBy(fn ($e) => $e->id()) as $e) {
        $data = $e->data()->all();
        $rows[$e->id()] = [
            'collection' => $e->collectionHandle(), 'site' => $e->locale(), 'origin' => $e->origin()?->id(),
            'slug' => $e->slug(), 'uri' => $e->uri(), 'url' => $e->url(), 'published' => $e->published(),
            'status' => $e->status(), 'date' => $e->collection()->dated() ? $e->date()?->toIso8601String() : null,
            'has_explicit_date' => method_exists($e, 'hasExplicitDate') ? $e->hasExplicitDate() : null,
            'blueprint' => $e->blueprint()?->handle(), 'parent' => $e->parent()?->id(),
            'order' => $e->order(), 'path' => str_replace(base_path().'/', '', (string) $e->path()),
            'keys' => array_keys($data), 'data' => fp($data), 'values' => fp($e->values()),
        ];
    }
    $result['entries'] = $rows;
}

if (in_array('queries', $sections)) {
    $q = [];
    $ids = fn ($c) => collect($c)->map(fn ($e) => is_object($e) ? $e->id() : $e)->values()->all();
    // queries without an orderBy have no defined order (it follows file traversal): compare as sets
    $set = fn ($c) => collect($ids($c))->sort()->values()->all();
    foreach (Collection::all() as $col) {
        $h = $col->handle();
        $q["$h:count"] = Entry::query()->where('collection', $h)->count();
        $q["$h:all-ids"] = $set(Entry::query()->where('collection', $h)->get());
        foreach ($col->sites() as $site) {
            $base = fn () => Entry::query()->where('collection', $h)->where('site', $site);
            $q["$h:$site:count"] = $base()->count();
            $q["$h:$site:published"] = $set($base()->where('published', true)->get());
            $q["$h:$site:status-published"] = $set($base()->whereStatus('published')->get());
            $q["$h:$site:title-asc-10"] = $ids($base()->orderBy('title')->limit(10)->get());
            $q["$h:$site:title-desc-offset"] = $ids($base()->orderBy('title', 'desc')->offset(5)->limit(5)->get());
            if ($col->dated()) {
                $q["$h:$site:date-desc-12"] = $ids($base()->where('published', true)->orderBy('date', 'desc')->limit(12)->get());
                $q["$h:$site:date-before-2024"] = $ids($base()->whereDate('date', '<', '2024-01-01')->orderBy('date')->get());
            }
            // Older Statamic 5 ignores orderBy() in pluck() and returns index order, which isn't
            // stable even in plain Statamic: compare as a set when that happens (get() honours it).
            $plucked = $base()->orderBy('title')->pluck('title')->all();
            $ordered = $base()->orderBy('title')->get()->map->get('title')->all();
            $q["$h:$site:pluck-title"] = $plucked === $ordered ? $plucked : ['unordered' => collect($plucked)->sort()->values()->all()];
            $q["$h:$site:pluck-slug-by-id"] = $base()->pluck('slug', 'id')->all();
            $q["$h:$site:first"] = eid($base()->orderBy('slug')->first());
            $q["$h:$site:paginate-2"] = $ids($base()->orderBy('slug')->paginate(7, ['*'], 'page', 2)->items());
            $q["$h:$site:where-like"] = $ids($base()->where('title', 'like', '%a%')->orderBy('id')->get());
            $q["$h:$site:origin-null"] = $base()->whereNull('origin')->count();
            $q["$h:$site:where-in-slug"] = $set($base()->whereIn('slug', ['home', 'about', 'contact', 'services', 'missing'])->get());
            foreach ($col->taxonomies() as $tax) {
                $term = $tax->queryTerms()->first();
                if ($term) $q["$h:$site:tax-{$tax->handle()}"] = $set($base()->whereTaxonomy($tax->handle().'::'.$term->slug())->get());
            }
        }
    }
    // URI lookups for a sample of every 25th entry (by id) plus missing ones
    $all = Entry::all()->sortBy(fn ($e) => $e->id())->values();
    foreach ($all->filter(fn ($e, $i) => $i % 25 === 0) as $e) {
        if ($e->uri()) $q['findByUri:'.$e->locale().':'.$e->uri()] = eid(Entry::findByUri($e->uri(), $e->locale()));
        $q['find:'.$e->id()] = eid(Entry::find($e->id()));
    }
    $q['findByUri:missing'] = eid(Entry::findByUri('/definitely/not/here', $sites[0]));
    $result['queries'] = $q;
}

if (in_array('structures', $sections)) {
    $s = [];
    foreach (Collection::all()->filter->hasStructure() as $col) {
        foreach ($col->sites() as $site) {
            $tree = $col->structure()->in($site); if (! $tree) continue;
            $tree->withEntries();
            $s[$col->handle().':'.$site] = $tree->flattenedPages()->map(fn ($p) => [$p->id(), $p->uri(), $p->depth() ?? null, $p->parent()?->id()])->values()->all();
        }
    }
    foreach (\Statamic\Facades\Nav::all() as $nav) {
        foreach ($sites as $site) {
            $tree = $nav->in($site); if (! $tree) continue;
            $s['nav:'.$nav->handle().':'.$site] = $tree->flattenedPages()->map(fn ($p) => [$p->id(), $p->url(), $p->title()])->values()->all();
        }
    }
    $result['structures'] = $s;
}

if (in_array('terms', $sections)) {
    $t = [];
    foreach (Taxonomy::all() as $tax) {
        foreach ($tax->queryTerms()->get()->sortBy(fn ($x) => $x->id())->take(15) as $term) {
            $t[$term->id()] = ['entries_count' => $term->entriesCount(), 'entries' => $term->queryEntries()->get()->map->id()->sort()->values()->all()];
        }
    }
    $result['terms'] = $t;
}

$result['meta']['seconds'] = round((hrtime(true) - $t0) / 1e9, 1);
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode(['out' => $out] + array_map(fn ($s) => is_array($s) ? count($s) : $s, array_diff_key($result, ['meta' => 1])) + ['seconds' => $result['meta']['seconds']]), "\n";

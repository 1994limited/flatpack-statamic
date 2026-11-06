<?php
// Deterministic content changes through Statamic's API (what the Control Panel does). Run against
// the oracle app and the Flatpack app, then compare the files written and a parity dump.
// php mutate.php <app dir> <log.json>
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

[$_, $appDir, $log] = $argv;
require "$appDir/vendor/autoload.php";
$app = require "$appDir/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Carbon::setTestNow('2026-11-05 12:00:00');   // the same updated_at in both apps

$done = [];
$pick = fn ($col, $site, $filter = null) => Entry::query()->where('collection', $col)->where('site', $site)->get()
    ->sortBy(fn ($e) => $e->id())->first(fn ($e) => $filter ? $filter($e) : true);
$step = function ($name, $fn) use (&$done) {
    try { $r = $fn(); $err = null; } catch (\Throwable $e) { $r = null; $err = get_class($e).': '.$e->getMessage(); }
    $done[] = ['step' => $name, 'ref' => $r, 'error' => $err];
};

$step('update-title', function () use ($pick) { $e = $pick('blog', 'en'); $e->set('title', 'Parity: updated title')->save(); return $e->id(); });
$step('edit-page-builder', function () use ($pick) {
    $e = $pick('pages', 'en', fn ($e) => is_array($e->get('page_builder')) && count($e->get('page_builder')) > 1);
    $pb = $e->get('page_builder'); $pb[] = $pb[0]; $pb[count($pb) - 1]['id'] = 'parityblock'; array_shift($pb);
    $e->set('page_builder', $pb)->save(); return $e->id(); });
$step('unpublish', function () use ($pick) { $e = $pick('blog', 'en', fn ($e) => $e->published()); $e->published(false)->save(); return $e->id(); });
// hasExplicitDate() is Statamic 5.5x+; before it, every dated entry's date came from its filename
$step('change-date', function () use ($pick) { $e = $pick('blog', 'en', fn ($e) => method_exists($e, 'hasExplicitDate') ? $e->hasExplicitDate() : $e->date() !== null); $e->date('2025-02-03')->save(); return $e->id(); });
$step('change-terms', function () use ($pick) { $e = $pick('blog', 'en'); $e->set('tags', array_slice(array_merge((array) $e->get('tags'), ['parity-tag']), 0, 3))->save(); return $e->id(); });
$step('rename-parent-slug', function () {
    $tree = Collection::find('pages')->structure()->in('en');
    $page = $tree->flattenedPages()->first(fn ($p) => $p->pages()->all()->isNotEmpty() && $p->entry());
    $e = $page->entry(); $e->slug($e->slug().'-renamed')->save(); return $e->id(); });
$step('move-in-tree', function () {
    $tree = Collection::find('pages')->structure()->in('en');
    $flat = $tree->flattenedPages()->filter(fn ($p) => $p->entry())->values();
    $leaf = $flat->first(fn ($p) => $p->pages()->all()->isEmpty() && $p->depth() >= 2);
    $root = $tree->root()['entry'] ?? null;
    $target = $flat->first(fn ($p) => $p->depth() === 1 && $p->id() !== $root && $p->id() !== $leaf->parent()?->id());
    $tree->move($leaf->id(), $target->id())->save(); return [$leaf->id(), $target->id()]; });
$step('create', function () {
    $e = Entry::make()->id('11111111-2222-4333-8444-555555555555')->collection('blog')->locale('en')
        ->slug('parity-new-post')->date('2026-01-02')->data(['title' => 'Parity new post', 'tags' => ['tag-1']]);
    $e->save(); return $e->id(); });
$step('localize', function () {
    $o = Entry::find('11111111-2222-4333-8444-555555555555');
    $l = $o->makeLocalization('fr')->id('11111111-2222-4333-8444-666666666666')->set('title', 'Nouvel article');
    $l->save(); return $l->id(); });
$step('delete-leaf', function () {
    $tree = Collection::find('pages')->structure()->in('en');
    $leaf = $tree->flattenedPages()->filter(fn ($p) => $p->entry() && $p->pages()->all()->isEmpty() && ! $p->entry()->descendants()->count())->sortBy(fn ($p) => $p->id())->first();
    $leaf->entry()->delete(); return $leaf->id(); });
$step('delete-plain', function () use ($pick) { $e = $pick('team', 'en'); $e->delete(); return $e->id(); });

file_put_contents($log, json_encode($done, JSON_PRETTY_PRINT));
echo json_encode(array_map(fn ($d) => $d['step'].':'.($d['error'] ? 'ERROR '.$d['error'] : 'ok'), $done)), "\n";

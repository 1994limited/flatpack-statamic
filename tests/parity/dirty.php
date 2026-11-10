<?php
// Parity for change tracking: what an entry says its original state was, and whether it's
// dirty, after it has been read and then changed in memory. Nothing is saved.
// php dirty.php <appdir> <out.json>

use Statamic\Facades\Entry;

[$_, $appDir, $out] = $argv;
require "$appDir/vendor/autoload.php";
$app = require "$appDir/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$state = fn ($e, array $keys) => [
    'dirty' => $e->isDirty(),
    'dirty_keys' => array_values(array_filter($keys, fn ($k) => $e->isDirty($k))),
    'original' => array_map(fn ($k) => $e->getOriginal($k), array_combine($keys, $keys)),
];
$keys = ['origin', 'slug', 'date', 'published', 'title', 'locale', 'collection'];

// Each case starts from a freshly read entry. Statamic remembers origins per request, and the
// unsaved changes made here would otherwise be remembered into the next case.
$fresh = function ($id) {
    Statamic\Facades\Blink::flush();

    return Entry::find($id);
};
$result = [];

$translations = Entry::query()->where('collection', 'blog')->where('site', 'fr')->get()->filter(fn ($e) => $e->hasOrigin())->sortBy->id()->values();
$posts = Entry::query()->where('collection', 'blog')->where('site', 'en')->get()->sortBy->id()->values();

foreach ($translations->take(3) as $i => $translation) {
    $id = $translation->id();
    $other = $posts->first(fn ($e) => $e->id() !== $translation->origin()->id());

    $e = $fresh($id);
    $result["$i untouched"] = $state($e, $keys);

    $e = $fresh($id);
    $e->origin($other);
    $result["$i origin changed"] = $state($e, $keys) + ['origin_now' => $e->origin()->id()];

    $e = $fresh($id);
    $e->origin(null);
    $result["$i origin removed"] = $state($e, $keys) + ['origin_now' => $e->origin()?->id()];

    $e = $fresh($id);
    $e->set('title', 'Changed in memory')->slug('changed-in-memory')->published(! $e->published());
    $result["$i data, slug and published changed"] = $state($e, $keys);
}

foreach ($posts->take(3) as $i => $post) {
    $e = $fresh($post->id());
    $e->date()?->addDays(3);
    $result["post $i date moved in place"] = $state($e, $keys);

    $e = $fresh($post->id());
    $e->data()->put('title', 'Put straight into the data');
    $result["post $i data changed through the collection"] = $state($e, $keys);

    $e = unserialize(serialize($fresh($post->id())));
    $e->set('title', 'After a round trip');
    $result["post $i serialised and back"] = $state($e, $keys);

    $e = clone $fresh($post->id());
    $e->slug('cloned');
    $result["post $i cloned"] = $state($e, $keys);
}

file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT));
echo count($result), " dirty-state answers\n";

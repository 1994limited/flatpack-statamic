<?php
// Generic, deterministic test content for the parity suite: three sites, a structured multisite
// pages collection with large page builders, a dated multisite blog with localized tags, a
// single-site team collection, categories, a nav and globals. Nothing in it is real.
// php generate.php <app dir> [entries per collection = 120]

[$_, $app] = $argv;
$n = (int) ($argv[2] ?? 120);
mt_srand(1994);
$c = "$app/content";
foreach (['collections', 'taxonomies', 'navigation', 'globals', 'trees'] as $d) {
    exec('rm -rf '.escapeshellarg("$c/$d"));
}

$write = function (string $path, string $contents) {
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $contents);
};
$yaml = fn (array $data) => Symfony\Component\Yaml\Yaml::dump($data, 20, 2, Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
require "$app/vendor/autoload.php";

$words = explode(' ', 'harbour lantern meadow copper willow granite orchard compass ember quarry thistle beacon cobble falcon juniper marble hollow saffron tundra velvet');
$title = fn (int $w = 3) => ucfirst(implode(' ', array_map(fn () => $words[mt_rand(0, count($words) - 1)], range(1, $w))));
$uuid = function (string $seed) { $h = md5($seed); return substr($h, 0, 8).'-'.substr($h, 8, 4).'-4'.substr($h, 13, 3).'-8'.substr($h, 17, 3).'-'.substr($h, 20, 12); };
$para = fn (int $words) => implode(' ', array_map(fn () => $GLOBALS['w'][mt_rand(0, 19)], range(1, $words)));
$GLOBALS['w'] = $words;
// A page builder big enough that Flatpack defers it (several KB of YAML).
$builder = function (int $blocks) use ($para, $title) {
    $out = [];
    for ($i = 0; $i < $blocks; $i++) {
        $out[] = match ($i % 3) {
            0 => ['id' => 'b'.mt_rand(), 'type' => 'text', 'enabled' => true, 'heading' => $title(4), 'text' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $para(60)]]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => $para(12)], ['type' => 'text', 'text' => ' '.$para(40)]]],
            ]],
            1 => ['id' => 'b'.mt_rand(), 'type' => 'cards', 'enabled' => true, 'cards' => array_map(fn ($k) => ['id' => 'c'.mt_rand(), 'title' => $title(2), 'summary' => $para(25), 'link' => '/'.strtolower(str_replace(' ', '-', $title(1)))], range(1, 4))],
            2 => ['id' => 'b'.mt_rand(), 'type' => 'quote', 'enabled' => (bool) mt_rand(0, 1), 'quote' => $para(30), 'cite' => $title(2)],
        };
    }

    return $out;
};

$sites = ['en' => ['name' => 'English', 'url' => '/', 'locale' => 'en_GB'], 'fr' => ['name' => 'French', 'url' => '/fr/', 'locale' => 'fr_FR'], 'de' => ['name' => 'German', 'url' => '/de/', 'locale' => 'de_DE']];
$write("$app/resources/sites.yaml", $yaml($sites));

// ---- taxonomies
$write("$c/taxonomies/tags.yaml", $yaml(['title' => 'Tags', 'sites' => array_keys($sites)]));
$tags = [];
for ($i = 1; $i <= 12; $i++) {
    $slug = "tag-$i";
    $tags[] = $slug;
    $data = ['title' => "Tag $i"];
    if ($i % 2) $data['localizations'] = ['fr' => ['title' => "Étiquette $i"]];
    if ($i % 3 === 0) $data['localizations']['de'] = ['title' => "Schlagwort $i"];
    $write("$c/taxonomies/tags/$slug.yaml", $yaml($data));
}
$write("$c/taxonomies/categories.yaml", $yaml(['title' => 'Categories']));
foreach (['news', 'guides', 'releases', 'events'] as $slug) {
    $write("$c/taxonomies/categories/$slug.yaml", $yaml(['title' => ucfirst($slug)]));
}

// ---- pages: structured, multisite, page builders, localizations in fr/de
$write("$c/collections/pages.yaml", $yaml(['title' => 'Pages', 'sites' => array_keys($sites), 'propagate' => false, 'route' => '{parent_uri}/{slug}', 'structure' => ['root' => true, 'max_depth' => 4]]));
$pages = [];
$fixed = ['home', 'about', 'contact', 'services'];
for ($i = 0; $i < $n; $i++) {
    $id = $uuid("page-$i");
    $slug = $fixed[$i] ?? strtolower(str_replace(' ', '-', $title(2))).'-'.$i;
    $data = ['id' => $id, 'title' => $i === 0 ? 'Home' : $title(3)];
    if ($i % 4 !== 3) $data['page_builder'] = $builder(3 + $i % 5);
    if ($i % 7 === 0) $data['intro'] = $para(20);
    if ($i % 11 === 5) $data['published'] = false;
    $data['updated_at'] = 1700000000 + $i;
    $write("$c/collections/pages/en/$slug.md", "---\n".$yaml($data)."---\n");
    $pages[$i] = $id;
    foreach (['fr' => 2, 'de' => 3] as $site => $every) {
        if ($i % $every) continue;
        $lid = $uuid("page-$i-$site");
        $ld = ['id' => $lid, 'origin' => $id, 'title' => $data['title']." ($site)"];
        if ($i % ($every * 2) === 0 && isset($data['page_builder'])) $ld['page_builder'] = $builder(2);
        $write("$c/collections/pages/$site/$slug.md", "---\n".$yaml($ld)."---\n");
        $pages["$i-$site"] = $lid;
    }
}
// trees: root home, then a nest three levels deep
foreach (array_keys($sites) as $site) {
    $ids = $site === 'en' ? array_filter($pages, fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY) : array_filter($pages, fn ($k) => str_ends_with((string) $k, "-$site"), ARRAY_FILTER_USE_KEY);
    $ids = array_values($ids);
    $tree = [['entry' => array_shift($ids)]];
    $top = [];
    foreach ($ids as $k => $id) {
        if ($k % 6 === 0 || ! $top) {
            $tree[] = ['entry' => $id];
            $top[] = count($tree) - 1;
        } else {
            $p = &$tree[end($top)];
            if ($k % 3 === 0 && ! empty($p['children'])) {
                $last = count($p['children']) - 1;
                $p['children'][$last]['children'][] = ['entry' => $id];
            } else {
                $p['children'][] = ['entry' => $id];
            }
            unset($p);
        }
    }
    $write("$c/trees/collections/$site/pages.yaml", $yaml(['tree' => $tree]));
}

// ---- blog: dated, multisite, tags and categories, some localized
$write("$c/collections/blog.yaml", $yaml(['title' => 'Blog', 'sites' => array_keys($sites), 'propagate' => false, 'date' => true, 'sort_dir' => 'desc', 'date_behavior' => ['past' => 'public', 'future' => 'unlisted'], 'route' => '/blog/{year}/{slug}', 'taxonomies' => ['tags', 'categories']]));
for ($i = 0; $i < $n; $i++) {
    $id = $uuid("post-$i");
    $date = date('Y-m-d', 1640995200 + $i * 86400 * 9);
    $slug = strtolower(str_replace(' ', '-', $title(3))).'-'.$i;
    $data = ['id' => $id, 'title' => $title(4), 'tags' => array_values(array_unique([$tags[$i % 12], $tags[($i * 5) % 12]])), 'categories' => [['news', 'guides', 'releases', 'events'][$i % 4]], 'body' => $para(80)];
    if ($i % 5 === 0) $data['page_builder'] = $builder(4);
    if ($i % 9 === 4) $data['published'] = false;
    $prefix = $i % 13 === 7 ? date('Y-m-d-Hi', 1640995200 + $i * 86400 * 9 + 3600 * 9) : $date;
    $write("$c/collections/blog/en/$prefix.$slug.md", "---\n".$yaml($data)."---\n");
    if ($i % 3 === 0) {
        $write("$c/collections/blog/fr/$prefix.$slug.md", "---\n".$yaml(['id' => $uuid("post-$i-fr"), 'origin' => $id, 'title' => $data['title'].' (fr)'])."---\n");
    }
}

// ---- team: single site, structured without root, orderable
$write("$c/collections/team.yaml", $yaml(['title' => 'Team', 'sites' => ['en'], 'route' => '/team/{slug}', 'structure' => ['max_depth' => 1]]));
$team = [];
for ($i = 0; $i < max(10, intdiv($n, 6)); $i++) {
    $id = $uuid("team-$i");
    $slug = strtolower(str_replace(' ', '-', $title(2))).'-'.$i;
    $write("$c/collections/team/en/$slug.md", "---\n".$yaml(['id' => $id, 'title' => $title(2), 'role' => $title(1), 'bio' => $para(50)])."---\n");
    $team[] = ['entry' => $id];
}
$write("$c/trees/collections/en/team.yaml", $yaml(['tree' => $team]));

// ---- nav and globals
$write("$c/navigation/main.yaml", $yaml(['title' => 'Main', 'collections' => ['pages'], 'max_depth' => 3]));
foreach (array_keys($sites) as $site) {
    $key = $site === 'en' ? 0 : "0-$site";
    $items = [['id' => $uuid("nav-$site-1"), 'entry' => $pages[$key] ?? $pages[0]], ['id' => $uuid("nav-$site-2"), 'title' => 'External', 'url' => 'https://example.com', 'children' => [['id' => $uuid("nav-$site-3"), 'entry' => $pages[$site === 'en' ? 2 : "6-$site"] ?? $pages[2]]]]];
    $write("$c/trees/navigation/$site/main.yaml", $yaml(['tree' => $items]));
}
$write("$c/globals/settings.yaml", $yaml(['title' => 'Settings']));
foreach (array_keys($sites) as $site) {
    $write("$c/globals/$site/settings.yaml", $yaml($site === 'en' ? ['site_name' => 'Parity test', 'footer' => $para(15)] : ['origin' => 'en', 'site_name' => "Parity test ($site)"]));
}

echo "generated: $n pages, $n posts, ".count($team)." team, 12 tags, 4 categories, 3 sites\n";

<?php

namespace NineteenNinetyFour\Flatpack\Stache;

use Statamic\Stache\Traverser;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Same result as Statamic's Traverser (Finder: files, no dot files, no VCS dirs, sorted by name,
 * then stably by mtime), without Finder's iterator stack and its realpath()-per-comparison sort.
 */
class FastTraverser extends Traverser
{
    private const VCS = ['.svn' => 1, '_svn' => 1, 'CVS' => 1, '_darcs' => 1, '.arch-params' => 1, '.monotone' => 1, '.bzr' => 1, '.git' => 1, '.hg' => 1];

    public function traverse($store)
    {
        if (! $dir = $store->directory()) {
            throw new \Exception("Store [{$store->key()}] does not have a directory defined.");
        }
        $dir = rtrim($dir, '/');
        if (! file_exists($dir)) {
            return collect();
        }
        $files = [];
        $this->walk($dir, '', $files);
        sort($files, SORT_STRING);

        $filter = $this->filter;
        $fast = $filter ? $this->fastFilter($filter) : null;
        $paths = [];
        foreach ($files as $rel) {
            $pathname = $dir.'/'.$rel;
            if ($fast) {
                $r = $fast($rel);
                if ($r === false) continue;
                if ($r === null && ! $filter(new SplFileInfo($pathname, ($p = strrpos($rel, '/')) === false ? '' : substr($rel, 0, $p), $rel))) continue;
            } elseif ($filter) {
                $p = strrpos($rel, '/');
                $info = new SplFileInfo($pathname, $p === false ? '' : substr($rel, 0, $p), $rel);
                if (! $filter($info)) continue;
            }
            $tidy = (str_contains($pathname, '//') || str_contains($pathname, '\\')) ? \Statamic\Facades\Path::tidy($pathname) : $pathname;
            $paths[$tidy] = filemtime($pathname);
        }
        asort($paths);

        return collect($paths);
    }

    /**
     * Inline equivalents of the hot stores' getItemFilter() (exact classes only). The closure
     * returns true/false, or null to defer to the store's own filter.
     */
    private function fastFilter($filter): ?\Closure
    {
        if (! is_array($filter) || ! is_object($filter[0]) || ($filter[1] ?? null) !== 'getItemFilter') return null;
        $store = $filter[0];
        $ext = static function (string $rel): string {
            $b = ($p = strrpos($rel, '/')) === false ? $rel : substr($rel, $p + 1);
            return ($d = strrpos($b, '.')) === false ? '' : substr($b, $d + 1);
        };
        switch (get_class($store)) {
            case \Statamic\Stache\Stores\CollectionEntriesStore::class:
                if (! \Statamic\Facades\Site::multiEnabled()) return static fn ($rel) => $ext($rel) !== 'yaml';
                $sites = array_flip((fn () => $this->collection())->call($store)->sites()->all());
                return static function ($rel) use ($sites, $ext) {
                    if (($p = strpos($rel, '/')) === false) return null;
                    if (! isset($sites[substr($rel, 0, $p)])) return false;
                    return $ext($rel) !== 'yaml';
                };
            case \Statamic\Stache\Stores\TaxonomyTermsStore::class:
                return static fn ($rel) => $ext($rel) === 'yaml';
            case \Statamic\Stache\Stores\CollectionsStore::class:
                return static fn ($rel) => ! str_contains($rel, '/') && $ext($rel) === 'yaml';
        }

        return null;
    }

    private function walk(string $base, string $rel, array &$out): void
    {
        $abs = $rel === '' ? $base : $base.'/'.$rel;
        $names = @scandir($abs, SCANDIR_SORT_NONE);
        if ($names === false) return;
        foreach ($names as $n) {
            if ($n[0] === '.' || isset(self::VCS[$n])) continue;
            $r = $rel === '' ? $n : $rel.'/'.$n;
            $p = $abs.'/'.$n;
            if (is_dir($p)) {
                if (! is_link($p)) $this->walk($base, $r, $out);
            } elseif (is_file($p)) {
                $out[] = $r;
            }
        }
    }
}

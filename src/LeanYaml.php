<?php

namespace NineteenNinetyFour\Flatpack;

use NineteenNinetyFour\Flatpack\Lazy\LazyBody;
use Statamic\Yaml\Yaml;

/**
 * Index-first parsing for entry files: top-level front-matter blocks larger than the limit are
 * not parsed; a LazyBody placeholder (file path, size, mtime, content hash) takes their place.
 */
class LeanYaml extends Yaml
{
    public static int $leanFiles = 0;
    public static ?string $entriesDir = null;

    public function parse($str = null)
    {
        $file = $this->file;
        if ($file && is_string($str) && str_starts_with($str, '---') && str_ends_with($file, '.md') && $this->isEntryFile($file)) {
            [$fm, $body] = array_pad(preg_split("/\n---/", substr($str, 3), 2), 2, null);
            $fm = ltrim($fm, "\n");
            [$lean, $skipped] = Shallow::split($fm, Flatpack::$lazyLimit);
            if ($skipped) {
                self::$leanFiles++;
                $data = parent::parse("---\n".$lean."\n---".($body ?? ''));
                clearstatcache(true, $file);
                $ref = new LazyBody(hash('xxh3', $str), $file, strlen($str), filemtime($file));
                $ordered = [];
                foreach (self::topKeys($fm) as $k) {
                    $ordered[$k] = isset($skipped[$k]) ? $ref : ($data[$k] ?? null);
                }

                return $ordered + $data;   // keys only the parser produced (e.g. `content`) go last
            }
        }

        return func_num_args() ? parent::parse($str) : parent::parse();
    }

    private function isEntryFile(string $file): bool
    {
        self::$entriesDir ??= rtrim((string) config('statamic.stache.stores.entries.directory'), '/').'/';

        return str_starts_with($file, self::$entriesDir);
    }

    private static function topKeys(string $yaml): array
    {
        $keys = [];
        foreach (explode("\n", $yaml) as $line) {
            if ($line === '' || in_array($line[0], [' ', '-', '#', "\t"], true)) continue;
            if (($c = strpos($line, ':')) !== false) $keys[] = trim(substr($line, 0, $c), " '\"");
        }

        return $keys;
    }
}

<?php

namespace NineteenNinetyFour\Flatpack;

use Symfony\Component\Yaml\Yaml as SymfonyYaml;

/** Shared settings and helpers. */
final class Flatpack
{
    public static string $path = '';
    public static int $lazyLimit = 2048;

    /** The Stache's cache store before Flatpack replaced it: ['store' => name, 'driver' => driver]. */
    public static array $replacedStore = [];
    private static ?BodyStore $bodies = null;

    public static function configure(string $path, int $lazyLimit = 2048): void
    {
        self::$path = rtrim($path, '/');
        self::$lazyLimit = $lazyLimit;
        self::$bodies = null;
    }

    private static ?bool $statamicHasFastParser = null;

    public static function bodies(): BodyStore
    {
        return self::$bodies ??= new BodyStore(self::$path.'/bodies');
    }

    /** Full parse of a file's YAML front matter (the slow path, used for heavy fields on demand). */
    public static function parseFrontMatter(string $contents): array
    {
        $fm = str_starts_with($contents, '---') ? preg_split("/\n---/", $contents, 2, PREG_SPLIT_NO_EMPTY)[0] : $contents;
        $fm = preg_replace('/^---\n?/', '', $fm);

        if (self::statamicHasFastParser()) {
            return (new \Statamic\Yaml\FastParser)->parse($fm) ?? [];
        }

        return Yaml\FastParser::parse($fm) ?? SymfonyYaml::parse($fm) ?? [];
    }

    /**
     * Whether this Statamic version has its own fast YAML parser, which Flatpack then uses
     * instead of the copy it ships for older versions.
     */
    public static function statamicHasFastParser(): bool
    {
        return self::$statamicHasFastParser ??= method_exists(\Statamic\Yaml\FastParser::class, 'parse');
    }

    /** True when $v holds only scalars, nulls and arrays of them (safe for var_export + OPcache). */
    public static function isPlain($v): bool
    {
        if (is_array($v)) {
            foreach ($v as $x) if (! self::isPlain($x)) return false;

            return true;
        }

        return $v === null || is_scalar($v);
    }

    /** Write a file atomically. */
    public static function writeAtomic(string $file, string $contents): void
    {
        @mkdir(dirname($file), 0775, true);
        $tmp = $file.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $contents);
        rename($tmp, $file);
    }
}

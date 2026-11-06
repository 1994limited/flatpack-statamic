<?php

namespace NineteenNinetyFour\Flatpack;

use Illuminate\Support\Facades\Cache;

/** What Flatpack has built, and what's changed since: shared by the utility and `flatpack:status`. */
final class Status
{
    public const RUNNING = 'flatpack::running';
    public const LAST_RUN = 'flatpack::last-run';

    public static function enabled(): bool
    {
        return (bool) config('flatpack.enabled', true);
    }

    public static function built(): bool
    {
        return is_file(Flatpack::$path.'/pointer.json');
    }

    /** The last full build: from `built.json`, or when the compiled files were last written. */
    public static function builtAt(): ?int
    {
        if (! self::built()) {
            return null;
        }

        return Builder::stamps()['built_at'] ?? filemtime(Flatpack::$path.'/pointer.json');
    }

    /** What did the last full build: `flatpack:build`, or Statamic's own warm. */
    public static function builtVia(): ?string
    {
        return self::built() ? (Builder::stamps()['via'] ?? null) : null;
    }

    public static function refreshedAt(): ?int
    {
        return self::built() ? (Builder::stamps()['refreshed_at'] ?? null) : null;
    }

    /** @return array{shards: int, indexes: int, bodies: int, bytes: int} */
    public static function files(): array
    {
        $count = fn (string $dir) => count(glob(Flatpack::$path."/{$dir}/*") ?: []);
        $bytes = 0;
        if (is_dir(Flatpack::$path)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(Flatpack::$path, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $bytes += $f->getSize();
            }
        }

        return ['shards' => $count('shards'), 'indexes' => $count('kv'), 'bodies' => $count('bodies'), 'bytes' => $bytes];
    }

    /**
     * Content files changed on disk since the last build or refresh.
     *
     * @return array{manifest: bool, count: int, paths: list<string>}
     */
    public static function pending(int $limit = 20): array
    {
        if (! self::built() || ! Builder::hasManifest()) {
            return ['manifest' => false, 'count' => 0, 'paths' => []];
        }

        $paths = array_merge(...array_values(Builder::changes()[0]) ?: [[]]);
        $base = rtrim(base_path(), '/').'/';

        return [
            'manifest' => true,
            'count' => count($paths),
            'paths' => array_map(fn ($p) => str_starts_with($p, $base) ? substr($p, strlen($base)) : $p, array_slice($paths, 0, $limit)),
        ];
    }

    public static function opcache(): bool
    {
        return function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false);
    }

    public static function pcntl(): bool
    {
        return function_exists('pcntl_fork');
    }

    public static function running(): ?array
    {
        return self::fromCache(self::RUNNING);
    }

    public static function lastRun(): ?array
    {
        return self::fromCache(self::LAST_RUN);
    }

    /** The status page and command must still work when the application cache doesn't. */
    private static function fromCache(string $key): ?array
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Cache drivers that every server of a site can share. */
    public const SHARED_DRIVERS = ['redis', 'memcached', 'database', 'dynamodb', 'valkey', 'mongodb'];

    /**
     * Without Flatpack, the Stache would live in a cache every server shares: then a Control Panel
     * save shows on every server at once. With Flatpack it lives on each server's own disk.
     *
     * @return array{store: string, driver: string}|null
     */
    public static function replacedSharedStore(): ?array
    {
        if (! config('flatpack.warn_shared_stache', true)) {
            return null;
        }

        $r = Flatpack::$replacedStore;

        return in_array($r['driver'] ?? null, self::SHARED_DRIVERS, true) ? $r : null;
    }

    /** Whether the utility's Build and Refresh buttons are on. */
    public static function controlPanelActions(): bool
    {
        return (bool) config('flatpack.control_panel_actions', true);
    }

    public static function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 0).' KB';
    }
}

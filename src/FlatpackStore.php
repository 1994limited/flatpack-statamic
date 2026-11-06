<?php

namespace NineteenNinetyFour\Flatpack;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\RetrievesMultipleKeys;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\Filesystem;

/**
 * Laravel cache store for the Stache that keeps its data as PHP files OPcache can serve from
 * shared memory:
 *   - entry items  -> one shard per collection: shards/<hash>.php returning [id => row]
 *   - plain values -> kv/<hash>.php returning the value (indexes, paths, keys, timestamps…)
 *   - anything else (objects of other stores) -> an ordinary FileStore underneath
 * Files are content-addressed and never rewritten; pointer.json maps logical names to the current
 * file and is read without OPcache, so a CLI compile is seen by PHP-FPM workers immediately.
 * Writes are buffered and persisted at the end of the request under a lock, merged with whatever
 * other processes wrote meanwhile.
 */
final class FlatpackStore implements Store
{
    use RetrievesMultipleKeys;

    private const ITEM_PREFIX = 'stache::items::entries::';

    private static array $instances = [];

    private ?array $pointer = null;     // logical name => physical file (relative)
    private array $loaded = [];         // logical name => decoded file contents (this request)
    private array $kvDirty = [];        // logical => value | null (delete)
    private array $shardOps = [];       // shard logical => [id => row | null]
    private FileStore $fallback;
    private array $fbRead = [];
    private array $fbOps = [];

    public static int $includes = 0, $writes = 0;
    public static int $buckets = 32;
    public static array $fallbackItems = [];

    public function __construct(private string $dir)
    {
        $this->fallback = new FileStore(new Filesystem, $dir.'/fallback');
        self::$instances[] = $this;
    }

    public static function persistAll(): void
    {
        foreach (self::$instances as $s) $s->persist();
    }

    // ------------------------------------------------------------------ reads

    public function get($key)
    {
        if ($item = $this->itemKey($key)) {
            [$shard, $id] = $item;
            $ops = $this->shardOps[$shard] ?? [];
            $row = array_key_exists($id, $ops) ? $ops[$id] : ($this->load($shard)[$id] ?? null);
            if ($row !== null) return $row === false ? $this->fbGet($key) : EntryCodec::decode($row);
            return $this->fbGet($key);
        }
        $logical = 'kv:'.$key;
        if (array_key_exists($logical, $this->kvDirty)) {
            // null marks not-a-kv-value: deleted, or an object that lives in the fallback store
            return $this->kvDirty[$logical] ?? $this->fbGet($key);
        }
        if (isset($this->pointer()[$logical])) return $this->load($logical);

        return $this->fbGet($key);
    }

    private function fbGet(string $key)
    {
        if (array_key_exists($key, $this->fbOps)) {
            $s = $this->fbOps[$key];

            return $s === null ? null : unserialize($s);
        }

        if (! array_key_exists($key, $this->fbRead)) {
            $this->fbRead[$key] = $this->readFallbackFile($key);
        }
        $s = $this->fbRead[$key];

        return $s === null ? null : unserialize($s);
    }

    /** The serialized payload FileStore::get() would unserialize, or null (missing/expired). */
    private function readFallbackFile(string $key): ?string
    {
        $c = @file_get_contents($this->fallback->path($key));
        if ($c === false || strlen($c) < 10) return null;
        $exp = (int) substr($c, 0, 10);
        if ($exp !== 9999999999 && time() >= $exp) return null;

        return substr($c, 10);
    }

    private function load(string $logical)
    {
        if (array_key_exists($logical, $this->loaded)) return $this->loaded[$logical];
        $file = $this->pointer()[$logical] ?? null;
        if ($file === null) return $this->loaded[$logical] = null;
        self::$includes++;

        return $this->loaded[$logical] = include $this->dir.'/'.$file;
    }

    private function pointer(): array
    {
        if ($this->pointer === null) {
            $s = @file_get_contents($this->dir.'/pointer.json');
            $this->pointer = $s ? json_decode($s, true) : [];
        }

        return $this->pointer;
    }

    /** @return array{0: string, 1: string}|null [shard logical name, entry id] */
    private function itemKey(string $key): ?array
    {
        if (! str_starts_with($key, self::ITEM_PREFIX)) return null;
        $rest = substr($key, strlen(self::ITEM_PREFIX));
        $p = strpos($rest, '::');
        if ($p === false) return null;

        $id = substr($rest, $p + 2);

        // a collection's rows are split into buckets so a request (cold OPcache especially) only
        // compiles/includes the rows near the ones it reads, and a save rewrites one small file
        return ['shard:'.substr($rest, 0, $p).'#'.(crc32($id) % self::$buckets), $id];
    }

    // ----------------------------------------------------------------- writes

    public function put($key, $value, $seconds)
    {
        return $this->forever($key, $value);
    }

    public function forever($key, $value)
    {
        if ($item = $this->itemKey($key)) {
            [$shard, $id] = $item;
            $row = is_object($value) ? EntryCodec::encode($value) : null;
            if ($row === null) {
                self::$fallbackItems[$key] = EntryCodec::$lastFailure;
                $this->fbOps[$key] = serialize($value);
                $row = false;   // marker: this item lives in the fallback store
            }
            $this->shardOps[$shard][$id] = $row;

            return true;
        }
        if (Flatpack::isPlain($value)) {
            $this->kvDirty['kv:'.$key] = $value;
            $this->fbOps[$key] = null;
        } else {
            $this->kvDirty['kv:'.$key] = null;
            $this->fbOps[$key] = serialize($value);
        }

        return true;
    }

    public function forget($key)
    {
        if ($item = $this->itemKey($key)) {
            $this->shardOps[$item[0]][$item[1]] = null;
        } else {
            $this->kvDirty['kv:'.$key] = null;
        }
        $this->fbOps[$key] = null;

        return true;
    }

    public function flush()
    {
        $this->kvDirty = $this->shardOps = $this->loaded = $this->fbOps = $this->fbRead = [];
        $this->pointer = [];
        (new Filesystem)->deleteDirectory($this->dir);

        return true;
    }

    public function increment($key, $value = 1)
    {
        $n = ((int) $this->get($key)) + $value;
        $this->forever($key, $n);

        return $n;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    /** Laravel 12.x+: extend a TTL. Stache values never expire. */
    public function touch($key, $seconds)
    {
        return $this->get($key) !== null;
    }

    public function getPrefix()
    {
        return '';
    }

    // ---------------------------------------------------------------- persist

    /** Write buffered changes: new content-addressed files, then the pointer, under a lock. */
    public function persist(): void
    {
        if (! $this->kvDirty && ! $this->shardOps && ! $this->fbOps) return;
        @mkdir($this->dir, 0775, true);
        $lock = fopen($this->dir.'/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            // re-read: other processes may have persisted since this request started
            $this->pointer = null;
            $this->loaded = [];
            $pointer = $this->pointer();
            foreach ($this->shardOps as $logical => $ops) {
                $rows = $this->load($logical) ?? [];
                foreach ($ops as $id => $row) {
                    if ($row === null) unset($rows[$id]); else $rows[$id] = $row;
                }
                $pointer = $this->writeLogical($pointer, $logical, $rows ?: null, 'shards');
            }
            foreach ($this->kvDirty as $logical => $value) {
                $pointer = $this->writeLogical($pointer, $logical, $value, 'kv');
            }
            $this->persistFallback();
            Flatpack::writeAtomic($this->dir.'/pointer.json', json_encode($pointer, JSON_UNESCAPED_SLASHES));
            $this->pointer = $pointer;
            $this->loaded = [];
            $this->kvDirty = $this->shardOps = $this->fbOps = $this->fbRead = [];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Same files FileStore::forever()/forget() would leave, written once per key. */
    private function persistFallback(): void
    {
        $dirs = [];
        foreach ($this->fbOps as $key => $ser) {
            $path = $this->fallback->path($key);
            if ($ser === null) {
                if (is_file($path)) @unlink($path);
                continue;
            }
            $dir = dirname($path);
            if (! isset($dirs[$dir])) { if (! is_dir($dir)) @mkdir($dir, 0777, true); $dirs[$dir] = true; }
            file_put_contents($path, '9999999999'.$ser, LOCK_EX);
        }
    }

    private function writeLogical(array $pointer, string $logical, $value, string $sub): array
    {
        if ($value === null) {
            unset($pointer[$logical]);

            return $pointer;
        }
        $php = '<?php return '.var_export($value, true).";\n";
        $rel = $sub.'/'.hash('xxh3', $php).'.php';
        if (! is_file($this->dir.'/'.$rel)) {
            Flatpack::writeAtomic($this->dir.'/'.$rel, $php);
            self::$writes++;
        }
        $pointer[$logical] = $rel;

        return $pointer;
    }

    /** Delete files no longer referenced by the pointer (run after a compile). */
    public function gc(): int
    {
        $live = array_flip(array_values($this->pointer()));
        $n = 0;
        foreach (['shards', 'kv'] as $sub) {
            foreach (glob($this->dir.'/'.$sub.'/*.php') ?: [] as $f) {
                if (! isset($live[$sub.'/'.basename($f)])) { @unlink($f); $n++; }
            }
        }

        return $n;
    }
}

<?php

namespace NineteenNinetyFour\Flatpack\Lazy;

use NineteenNinetyFour\Flatpack\Flatpack;

/**
 * Placeholder for an entry's heavy fields (one per entry, shared by all its heavy keys).
 * Loads from the content-addressed body file; on a miss parses the entry's file once and writes it.
 */
final class LazyBody
{
    public static int $loads = 0, $misses = 0, $stale = 0;
    public static float $ms = 0;

    public function __construct(public string $hash, public ?string $path = null, public ?int $size = null, public ?int $mtime = null) {}

    public function load(): array
    {
        $t = hrtime(true); self::$loads++;
        try {
            if ($body = Flatpack::bodies()->read($this->hash)) {
                return $body;
            }
            if (! $this->path) {
                throw new \RuntimeException("Flatpack body {$this->hash} missing and no source file recorded.");
            }
            clearstatcache(true, $this->path);
            if (@filesize($this->path) !== $this->size || @filemtime($this->path) !== $this->mtime) {
                self::$stale++;   // file changed since the index was built: read what is there now
            } else {
                self::$misses++;
            }
            $data = Flatpack::parseFrontMatter(file_get_contents($this->path));
            if (! self::$stale) {
                Flatpack::bodies()->write($this->hash, $data);
            }

            return $data;
        } finally {
            self::$ms += (hrtime(true) - $t) / 1e6;
        }
    }

    public function toArray(): array
    {
        return ['__lazy_body' => [$this->hash, $this->path, $this->size, $this->mtime]];
    }

    public static function fromArray(array $a): self
    {
        return new self(...$a['__lazy_body']);
    }

    public static function isEncoded($v): bool
    {
        return is_array($v) && count($v) === 1 && isset($v['__lazy_body']);
    }
}

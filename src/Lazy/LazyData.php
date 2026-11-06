<?php

namespace NineteenNinetyFour\Flatpack\Lazy;

use Illuminate\Support\Collection;

/**
 * An entry's data collection whose heavy fields are LazyBody placeholders until something reads
 * them. keys()/has()/count() and reads of other keys never load. Any read of all values does.
 * Use LazyData::fromItems() — it returns a subclass that also loads before every other Collection method.
 */
class LazyData extends Collection
{
    private bool $hydrated = false;

    public static function fromItems(array $items): Collection
    {
        foreach ($items as $v) {
            if ($v instanceof LazyBody) {
                $class = self::fullClass();

                return new $class($items);
            }
        }

        return new Collection($items);
    }

    public function hydrate(): static
    {
        if ($this->hydrated) {
            return $this;
        }
        $this->hydrated = true;
        foreach ($this->items as $v) {
            if ($v instanceof LazyBody) {
                $body = $v->load();
                foreach ($this->items as $k => $x) {
                    if ($x instanceof LazyBody) {
                        $this->items[$k] = $body[$k] ?? null;
                    }
                }
                break;
            }
        }

        return $this;
    }

    public function isHydrated(): bool { return $this->hydrated || ! $this->hasPlaceholders(); }

    public function hasPlaceholders(): bool
    {
        foreach ($this->items as $v) if ($v instanceof LazyBody) return true;

        return false;
    }

    /** Items with placeholders left in place (for compiling and dirty-state snapshots). */
    public function rawItems(): array { return $this->items; }

    /** Items for route data: placeholders dropped unless the route template names the key. */
    public function routeItems(string $route): array
    {
        $out = [];
        foreach ($this->items as $k => $v) {
            if (! $v instanceof LazyBody) { $out[$k] = $v; continue; }
            if (str_contains($route, (string) $k)) { return $this->hydrate()->items; }
        }

        return $out;
    }

    public function keys() { return new Collection(array_keys($this->items)); }
    public function has($key) { return parent::has($key); }
    public function count(): int { return count($this->items); }
    public function offsetExists($key): bool { return isset($this->items[$key]); }
    public function get($key, $default = null) { if (($this->items[$key] ?? null) instanceof LazyBody) $this->hydrate(); return parent::get($key, $default); }
    public function offsetGet($key): mixed { if (($this->items[$key] ?? null) instanceof LazyBody) $this->hydrate(); return parent::offsetGet($key); }
    public function all() { return $this->hydrate()->items; }
    public function getIterator(): \Traversable { return new \ArrayIterator($this->hydrate()->items); }
    public function toArray() { $this->hydrate(); return parent::toArray(); }
    public function jsonSerialize(): array { $this->hydrate(); return parent::jsonSerialize(); }
    public function __call($m, $a) { $this->hydrate(); return parent::__call($m, $a); }
    public function __serialize(): array { return ['items' => $this->items]; }
    public function __unserialize(array $d): void { $this->items = $d['items']; }

    private static function typeString(\ReflectionType $t): string
    {
        if ($t instanceof \ReflectionUnionType || $t instanceof \ReflectionIntersectionType) {
            $sep = $t instanceof \ReflectionUnionType ? '|' : '&';

            return implode($sep, array_map(fn ($x) => self::typeString($x), $t->getTypes()));
        }
        $name = $t->getName();
        $fq = ($t->isBuiltin() || in_array($name, ['self', 'static', 'parent'], true)) ? $name : '\\'.$name;

        return ($t->allowsNull() && ! in_array($name, ['mixed', 'null'], true) ? '?' : '').$fq;
    }

    /** A subclass overriding every other public Collection method to load first (generated once). */
    public static function fullClass(): string
    {
        if (class_exists(LazyDataFull::class, false)) {
            return LazyDataFull::class;
        }
        $skip = ['hydrate', 'isHydrated', 'hasPlaceholders', 'rawItems', 'routeItems', 'keys', 'has', 'count', 'offsetExists', 'get', 'offsetGet', 'all', 'getIterator', 'toArray', 'jsonSerialize', '__call', '__construct', '__get', '__serialize', '__unserialize',
            'fromItems', 'make', 'wrap', 'unwrap', 'times', 'range', 'empty', 'fromJson', 'macro', 'mixin', 'hasMacro', 'flushMacros', '__callStatic', 'proxy', 'escapeWhenCastingToString',
            'offsetSet', 'offsetUnset', 'put', 'push', 'prepend', 'pull', 'forget', 'add', 'isEmpty', 'isNotEmpty'];
        $code = '';
        foreach ((new \ReflectionClass(Collection::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->isStatic() || in_array($m->name, $skip, true)) continue;
            $rt = $m->hasReturnType() ? ': '.self::typeString($m->getReturnType()) : '';
            $ret = (string) $m->getReturnType() === 'void' ? '' : 'return ';
            $code .= "    public function {$m->name}(...\$a){$rt}\n    {\n        \$this->hydrate();\n\n        {$ret}parent::{$m->name}(...\$a);\n    }\n\n";
        }
        // Written to a real file (not eval'd) so it can be read, and OPcache can cache it. The
        // method list depends on the installed Laravel version, so the file name hashes it.
        $php = "<?php\n\n// Generated by Flatpack from Illuminate\\Support\\Collection's public methods. Do not edit.\n\n"
            ."namespace NineteenNinetyFour\\Flatpack\\Lazy;\n\nclass LazyDataFull extends LazyData\n{\n{$code}}\n";
        $dir = (\NineteenNinetyFour\Flatpack\Flatpack::$path ?: sys_get_temp_dir().'/flatpack').'/generated';
        $file = $dir.'/LazyDataFull-'.hash('xxh3', $php).'.php';
        if (! is_file($file)) {
            \NineteenNinetyFour\Flatpack\Flatpack::writeAtomic($file, $php);
        }
        require_once $file;

        return LazyDataFull::class;
    }
}

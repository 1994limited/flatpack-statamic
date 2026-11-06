<?php

namespace NineteenNinetyFour\Flatpack;

use Carbon\CarbonInterface;
use NineteenNinetyFour\Flatpack\Lazy\LazyBody;
use NineteenNinetyFour\Flatpack\Lazy\LazyData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Entry object <-> plain array, the same properties unserialize() would restore (Entry::__sleep),
 * so rows can live in OPcache'd PHP files instead of serialized cache files.
 * Heavy data fields become LazyBody references; if an entry can't be represented, encode() returns null.
 */
final class EntryCodec
{
    public static ?string $lastFailure = null;

    public static function encode(object $entry): ?array
    {
        $keep = $entry->__sleep();   // also resolves a slug closure, as serialize() would
        $vars = (fn () => get_object_vars($this))->call($entry);
        $out = [];
        foreach ($keep as $k) {
            $v = $vars[$k] ?? null;
            if ($k === 'data') {
                $items = self::encodeData($v);
                if ($items === null) { self::$lastFailure = 'data'; return null; }
                $out[$k] = ['__data' => $items];
                continue;
            }
            [$ok, $enc] = self::encodeValue($v);
            if (! $ok) { self::$lastFailure = $k.':'.get_debug_type($v); return null; }
            $out[$k] = $enc;
        }

        return ['class' => get_class($entry), 'vars' => $out];
    }

    public static function decode(array $row): object
    {
        $entry = (new \ReflectionClass($row['class']))->newInstanceWithoutConstructor();
        $vars = [];
        foreach ($row['vars'] as $k => $v) {
            $vars[$k] = is_array($v) && array_key_exists('__data', $v) ? LazyData::fromItems(self::decodeData($v['__data'])) : self::decodeValue($v);
        }
        (function () use ($vars) { foreach ($vars as $k => $v) $this->{$k} = $v; })->call($entry);

        return $entry;
    }

    private static function encodeData($data): ?array
    {
        $items = $data instanceof LazyData ? $data->rawItems() : ($data instanceof Collection ? $data->all() : (array) $data);
        $heavy = [];
        $hasRef = false;
        foreach ($items as $k => $v) {
            if ($v instanceof LazyBody) { $hasRef = true; continue; }
            if (is_array($v) && strlen(serialize($v)) > Flatpack::$lazyLimit && Flatpack::isPlain($v)) $heavy[$k] = $v;
        }
        // Heavy fields set through the API (CP saves): move them into a content-addressed body.
        if ($heavy && ! $hasRef) {
            $hash = hash('xxh3', serialize($heavy));
            Flatpack::bodies()->write($hash, $heavy);
            $ref = new LazyBody($hash);
            foreach ($heavy as $k => $_) $items[$k] = $ref;
        }
        foreach ($items as $k => $v) {
            if ($v instanceof LazyBody) { $items[$k] = $v->toArray(); continue; }
            if (! Flatpack::isPlain($v)) return null;
        }

        return $items;
    }

    private static function decodeData(array $items): array
    {
        $refs = [];
        foreach ($items as $k => $v) {
            if (LazyBody::isEncoded($v)) {
                $key = implode('|', $v['__lazy_body']);
                $items[$k] = $refs[$key] ??= LazyBody::fromArray($v);
            }
        }

        return $items;
    }

    /** @return array{0: bool, 1: mixed} [encodable?, encoded value] */
    private static function encodeValue($v): array
    {
        if ($v instanceof CarbonInterface) return [true, ['__carbon' => [$v->format('Y-m-d H:i:s.u'), $v->getTimezone()->getName()]]];
        if ($v instanceof Collection) return Flatpack::isPlain($v->all()) ? [true, ['__collection' => $v->all()]] : [false, null];
        if (Flatpack::isPlain($v)) return [true, $v];

        return [false, null];
    }

    private static function decodeValue($v)
    {
        if (is_array($v) && count($v) === 1) {
            if (isset($v['__carbon'])) return Carbon::createFromFormat('Y-m-d H:i:s.u', $v['__carbon'][0], $v['__carbon'][1]);
            if (array_key_exists('__collection', $v)) return new Collection($v['__collection']);
        }

        return $v;
    }
}

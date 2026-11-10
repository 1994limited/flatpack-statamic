<?php

namespace NineteenNinetyFour\Flatpack;

use NineteenNinetyFour\Flatpack\Lazy\LazyBody;
use NineteenNinetyFour\Flatpack\Lazy\LazyData;
use Statamic\Entries\Entry;
use Statamic\Facades\Blink;

/**
 * Statamic's Entry with lazy heavy fields. Two core paths read every value and would load all
 * bodies during indexing; both are routed around the placeholders here:
 *   - routeData(): a route template only sees the lazy fields it names
 *   - dirty-state snapshots keep placeholders instead of loading bodies
 * The dirty-state snapshot is also only worked out when something asks for it.
 */
class FlatpackEntry extends Entry
{
    /** This entry as it was when last read or saved, until its dirty-state snapshot is needed. */
    private ?self $asRead = null;

    public function data($data = null)
    {
        if (func_num_args() === 0) {
            return $this->data;
        }
        parent::data($data);
        if (! $this->data instanceof LazyData) {
            foreach ($this->data->all() as $v) {
                if ($v instanceof LazyBody) {
                    $this->data = LazyData::fromItems($this->data->all());
                    break;
                }
            }
        }

        return $this;
    }

    public function routeData()
    {
        $route = (string) $this->route();

        return $this->withSwappedData(fn ($d) => collect($d->routeItems($route)), fn () => parent::routeData());
    }

    public function getCurrentDirtyStateAttributes(): array
    {
        return $this->withSwappedData(fn ($d) => collect($d->rawItems()), fn () => parent::getCurrentDirtyStateAttributes());
    }

    /**
     * Statamic snapshots an entry's state every time one is read from the Stache, so it can
     * later say what changed. Most entries are never asked. Keeping a copy of the entry as it
     * was, and working the snapshot out from that copy only when something asks, costs a
     * shallow clone per read instead.
     */
    public function syncOriginal(): static
    {
        $this->asRead = null;
        $asRead = clone $this;

        // Older Statamic versions don't give a cloned entry its own data.
        if ($asRead->data === $this->data) {
            $asRead->data = clone $this->data;
        }

        // Dates can be changed in place, so the copy needs its own.
        if ($asRead->date instanceof \DateTimeInterface) {
            $asRead->date = clone $asRead->date;
        }

        $this->asRead = $asRead;

        return $this;
    }

    public function getOriginal($key = null, $fallback = null)
    {
        $this->resolveOriginal();

        return parent::getOriginal($key, $fallback);
    }

    public function isDirty($attributes = null): bool
    {
        $this->resolveOriginal();

        return parent::isDirty($attributes);
    }

    private function resolveOriginal(): void
    {
        if (! $asRead = $this->asRead) {
            return;
        }

        $this->asRead = null;

        if (! $asRead->origin) {
            $this->original = $asRead->getCurrentDirtyStateAttributes();

            return;
        }

        // Statamic remembers an entry's origin by the entry's id, so the copy would be given
        // this entry's origin as it is now. The copy has to see the origin it had when it was
        // read, and what's remembered has to be put back afterwards.
        $key = $asRead->getOriginBlinkKey();
        $remembered = Blink::get($key);

        if ($origin = $asRead->getOriginByString($asRead->origin)) {
            Blink::put($key, $origin);
        } else {
            Blink::forget($key);
        }

        try {
            $this->original = $asRead->getCurrentDirtyStateAttributes();
        } finally {
            $remembered ? Blink::put($key, $remembered) : Blink::forget($key);
        }
    }

    public function __sleep()
    {
        $this->resolveOriginal();

        return array_values(array_diff(parent::__sleep(), ['asRead']));
    }

    /** Run $fn with this entry's (and its origins') unloaded LazyData temporarily replaced. */
    private function withSwappedData(callable $swap, callable $fn)
    {
        $swapped = [];
        for ($e = $this, $i = 0; $e && $i < 10; $e = $e->origin(), $i++) {
            $d = (fn () => $this->data)->call($e);
            if ($d instanceof LazyData && ! $d->isHydrated()) {
                $swapped[] = [$e, $d];
                (fn () => $this->data = $swap($d))->call($e);
            }
            if (! method_exists($e, 'origin')) break;
        }
        try {
            return $fn();
        } finally {
            foreach ($swapped as [$e, $d]) (fn () => $this->data = $d)->call($e);
        }
    }
}

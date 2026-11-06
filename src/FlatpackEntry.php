<?php

namespace NineteenNinetyFour\Flatpack;

use NineteenNinetyFour\Flatpack\Lazy\LazyBody;
use NineteenNinetyFour\Flatpack\Lazy\LazyData;
use Statamic\Entries\Entry;

/**
 * Statamic's Entry with lazy heavy fields. Two core paths read every value and would load all
 * bodies during indexing; both are routed around the placeholders here:
 *   - routeData(): a route template only sees the lazy fields it names
 *   - dirty-state snapshots keep placeholders instead of loading bodies
 */
class FlatpackEntry extends Entry
{
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

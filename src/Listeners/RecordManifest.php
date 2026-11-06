<?php

namespace NineteenNinetyFour\Flatpack\Listeners;

use NineteenNinetyFour\Flatpack\Builder;
use NineteenNinetyFour\Flatpack\FlatpackStore;

/**
 * Statamic's own "Warm" (the Cache Manager button, `stache:warm`) fills Flatpack too, but only
 * records the file list refreshes compare against when its watcher is on. Record it every time,
 * so `flatpack:refresh` and the utility's "changed since" list keep working after a warm.
 */
class RecordManifest
{
    public static bool $paused = false;

    public function handle(): void
    {
        // Only when Flatpack is what the Stache is using: switched off, this must leave
        // Statamic's own warm (and the Cache Manager's Warm button) alone.
        if (self::$paused || ! \Statamic\Facades\Stache::cacheStore()->getStore() instanceof FlatpackStore) {
            return;
        }

        Builder::recordTimestamps();
        Builder::store()->persist();
        Builder::stampWarm();
    }
}

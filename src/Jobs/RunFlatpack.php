<?php

namespace NineteenNinetyFour\Flatpack\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use NineteenNinetyFour\Flatpack\Builder;
use NineteenNinetyFour\Flatpack\Status;
use Throwable;

/** A build or refresh started from the Control Panel: always runs on the server that got the click. */
class RunFlatpack
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /** @param 'build'|'refresh' $action */
    public function __construct(public string $action)
    {
    }

    public function handle(): void
    {
        @set_time_limit(0);
        $started = microtime(true);
        // Expires on its own, so a run killed mid-way (timeout, deploy) can't block the buttons for long.
        Cache::put(Status::RUNNING, ['action' => $this->action, 'at' => time()], 900);

        try {
            // A refresh can't follow the app to a new path: everything compiled holds absolute paths.
            $result = $this->action === 'build' || Builder::builtElsewhere()
                ? Builder::compile((int) config('flatpack.build_workers', 4))
                : Builder::refresh();

            Cache::forever(Status::LAST_RUN, [
                'action' => $this->action,
                'at' => time(),
                'seconds' => round(microtime(true) - $started, 2),
                'changed' => array_sum($result['changed_stores'] ?? []),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            Cache::forever(Status::LAST_RUN, [
                'action' => $this->action,
                'at' => time(),
                'seconds' => round(microtime(true) - $started, 2),
                'changed' => null,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            Cache::forget(Status::RUNNING);
        }
    }
}

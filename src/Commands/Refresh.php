<?php

namespace NineteenNinetyFour\Flatpack\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Flatpack\Builder;
use NineteenNinetyFour\Flatpack\Compatibility;
use NineteenNinetyFour\Flatpack\Flatpack;
use NineteenNinetyFour\Flatpack\Status;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Stache;

class Refresh extends Command
{
    use RunsInPlease;

    protected $signature = 'flatpack:refresh';

    protected $description = 'Update Flatpack with content files changed on disk since the last build';

    public function handle(): int
    {
        if (! Status::enabled()) {
            // Statamic's own Stache has no "only what changed" refresh to fall back to (its watcher
            // does that on requests, when it's on), so rebuild it: slower, but the result is right.
            Status::paused()
                ? $this->components->warn(Compatibility::message().' Rebuilding Statamic\'s own Stache instead.')
                : $this->components->info('Flatpack is switched off (FLATPACK_ENABLED=false), so rebuilding Statamic\'s own Stache instead.');
            $this->components->task('Clearing the Stache', fn () => Stache::clear());
            $this->components->task('Warming the Stache', fn () => Stache::warm());

            return self::SUCCESS;
        }

        if (! is_file(Flatpack::$path.'/pointer.json')) {
            $this->components->info('Nothing built yet, so building everything.');

            return $this->call('flatpack:build');
        }

        if (Builder::builtElsewhere()) {
            $this->components->info('The content was built for the app at another path (a new release directory), so building everything.');

            return $this->call('flatpack:build');
        }

        $out = Builder::refresh();

        if (! $out['changed_stores']) {
            $this->components->info('Up to date. Nothing changed on disk.');

            return self::SUCCESS;
        }

        $this->components->info('Refreshed in '.$out['refresh_ms'].' ms.');
        foreach ($out['changed_stores'] as $store => $count) {
            $this->components->twoColumnDetail($store, $count.' '.str('file')->plural($count));
        }
        if ($out['rederived_collections'] ?? []) {
            $this->components->twoColumnDetail('Collections re-indexed', implode(', ', $out['rederived_collections']));
        }

        return self::SUCCESS;
    }
}

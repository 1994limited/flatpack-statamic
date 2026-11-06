<?php

namespace NineteenNinetyFour\Flatpack\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Flatpack\Builder;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Stache;

class Build extends Command
{
    use RunsInPlease;

    protected $signature = 'flatpack:build
        {--workers= : Parallel workers for large fields (0 skips them; default from config)}';

    protected $description = 'Compile all content into Flatpack, ready to serve';

    public function handle(): int
    {
        if (! config('flatpack.enabled', true)) {
            return $this->stacheInstead();
        }

        $workers = $this->option('workers') !== null ? (int) $this->option('workers') : (int) config('flatpack.build_workers', 4);

        $this->components->task('Compiling content', function () use ($workers, &$out) {
            $out = Builder::compile($workers);
        });

        $this->components->twoColumnDetail('Compiled in', $out['compile_s'].' s');
        if (isset($out['bodies_compiled'])) {
            $this->components->twoColumnDetail('Large fields', $out['bodies_compiled'].' in '.$out['bodies_s'].' s ('.$out['workers'].' '.str('worker')->plural($out['workers']).')');
        }
        $this->components->twoColumnDetail('Old files removed', (string) $out['gc_files']);

        return self::SUCCESS;
    }

    /**
     * Flatpack is switched off: do what the deploy script did before Flatpack (clear and warm
     * Statamic's own Stache), so a deploy with only `flatpack:build` in it still works.
     */
    private function stacheInstead(): int
    {
        $this->components->info('Flatpack is switched off (FLATPACK_ENABLED=false), so warming Statamic\'s own Stache instead.');

        $this->components->task('Clearing the Stache', fn () => Stache::clear());
        $this->components->task('Warming the Stache', fn () => Stache::warm());

        return self::SUCCESS;
    }
}

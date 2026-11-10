<?php

namespace NineteenNinetyFour\Flatpack\Commands;

use Illuminate\Console\Command;
use NineteenNinetyFour\Flatpack\Compatibility;
use NineteenNinetyFour\Flatpack\Flatpack;
use NineteenNinetyFour\Flatpack\Status as State;
use Statamic\Console\RunsInPlease;

class Status extends Command
{
    use RunsInPlease;

    protected $signature = 'flatpack:status';

    protected $description = 'Show what Flatpack has compiled, and what has changed since';

    public function handle(): int
    {
        $row = fn (string $label, string $value) => $this->components->twoColumnDetail($label, $value);

        $row('Enabled', State::paused() ? '<fg=red>paused</>' : (State::enabled() ? '<fg=green>yes</>' : '<fg=yellow>no</>'));
        if (State::paused()) {
            $this->components->error(Compatibility::message());
        }
        $row('Path', Flatpack::$path ?: (string) config('flatpack.path'));
        $row('Stache cache store', (string) config('statamic.stache.cache_store'));
        if ($shared = State::replacedSharedStore()) {
            $row('Stache', "<fg=yellow>on this server's disk</> (was the shared ".($shared['store'] === $shared['driver'] ? $shared['store'] : "{$shared['store']} ({$shared['driver']})").' cache)');
        }
        $row('Control Panel build buttons', State::controlPanelActions() ? 'on' : 'off');
        $row('pcntl (parallel builds)', State::pcntl() ? 'available' : 'not available, builds run in one process');

        if ($shared) {
            $this->newLine();
            $this->components->warn('On one server this is fine. On several, a Control Panel save only shows on the server that took it until each runs `flatpack:refresh` (or a deploy rebuilds). Silence with FLATPACK_WARN_SHARED_STACHE=false.');
        }

        if (! State::built()) {
            $this->newLine();
            $this->components->warn('Nothing built yet. Run `php please flatpack:build`.');

            return self::SUCCESS;
        }

        $files = State::files();
        $row('Last built', date('Y-m-d H:i:s', State::builtAt()).(State::builtVia() ? ' ('.State::builtVia().')' : ''));
        if (State::builtVia() === 'stache:warm') {
            $row('Large fields', 'changed ones parse on first visit: `php please flatpack:build` pre-parses them');
        }
        $row('Last refreshed', State::refreshedAt() ? date('Y-m-d H:i:s', State::refreshedAt()) : 'not since');
        $row('Entry files', (string) $files['shards']);
        $row('Indexes', (string) $files['indexes']);
        $row('Large fields pre-parsed', (string) $files['bodies']);
        $row('Size on disk', State::bytes($files['bytes']));

        if (! State::enabled()) {
            return self::SUCCESS;
        }

        if (\NineteenNinetyFour\Flatpack\Builder::builtElsewhere()) {
            $row('Changed on disk since', '<fg=yellow>built for another path</> (a new release directory: run `php please flatpack:build`)');

            return self::SUCCESS;
        }

        $pending = State::pending();
        if (! $pending['manifest']) {
            $row('Changed on disk since', 'unknown: run `php please flatpack:build` to start tracking');
        } elseif ($pending['count'] === 0) {
            $row('Changed on disk since', 'nothing');
        } else {
            $row('Changed on disk since', "<fg=yellow>{$pending['count']}</> (run `php please flatpack:refresh`)");
            foreach ($pending['paths'] as $p) {
                $this->line("  <fg=gray>{$p}</>");
            }
        }

        return self::SUCCESS;
    }
}

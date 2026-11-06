<?php

namespace NineteenNinetyFour\Flatpack\Http\Controllers;

use Illuminate\Http\Request;
use NineteenNinetyFour\Flatpack\Flatpack;
use NineteenNinetyFour\Flatpack\Jobs\RunFlatpack;
use NineteenNinetyFour\Flatpack\Status;
use Statamic\Http\Controllers\CP\CpController;

class UtilityController extends CpController
{
    /** The data both utility views render. */
    public static function data(): array
    {
        $files = Status::files();
        $last = Status::lastRun();
        $running = Status::running();

        return [
            'enabled' => Status::enabled(),
            'built' => Status::built(),
            'builtAgo' => Status::builtAt() ? \Carbon\Carbon::createFromTimestamp(Status::builtAt())->diffForHumans() : null,
            'builtVia' => Status::builtVia(),
            'refreshedAgo' => Status::refreshedAt() ? \Carbon\Carbon::createFromTimestamp(Status::refreshedAt())->diffForHumans() : null,
            'path' => str_replace(base_path().'/', '', Flatpack::$path),
            'files' => $files,
            'size' => Status::bytes($files['bytes']),
            'pending' => Status::enabled() ? Status::pending() : ['manifest' => false, 'count' => 0, 'paths' => []],
            'opcache' => Status::opcache(),
            'pcntl' => Status::pcntl(),
            'running' => $running ? ['action' => $running['action'], 'ago' => \Carbon\Carbon::createFromTimestamp($running['at'])->diffForHumans()] : null,
            'lastRun' => $last ? $last + ['ago' => \Carbon\Carbon::createFromTimestamp($last['at'])->diffForHumans()] : null,
            'actions' => Status::controlPanelActions(),
            'sharedStore' => Status::replacedSharedStore(),
            'buildUrl' => cp_route('utilities.flatpack.build'),
            'refreshUrl' => cp_route('utilities.flatpack.refresh'),
        ];
    }

    public function build(Request $request)
    {
        return $this->run('build');
    }

    public function refresh(Request $request)
    {
        return $this->run('refresh');
    }

    private function run(string $action)
    {
        if (! Status::controlPanelActions()) {
            return back()->withErrors(__('Building from the Control Panel is turned off (FLATPACK_CP_ACTIONS=false). Run php please flatpack:build in your deploy.'));
        }

        if (! Status::enabled()) {
            return back()->withErrors(__('Flatpack is turned off (FLATPACK_ENABLED=false).'));
        }

        if (Status::running()) {
            return back()->withErrors(__('Flatpack is already running. Try again when it has finished.'));
        }

        // Always on this server, never queued: a queue worker can be another machine (a Laravel
        // Cloud worker cluster, a Forge worker server) with its own disk, and the build would
        // land there instead of on the server answering requests.
        try {
            RunFlatpack::dispatchSync($action);
        } catch (\Throwable $e) {
            return back()->withErrors($e->getMessage());
        }

        $last = Status::lastRun();

        return back()->withSuccess($action === 'build'
            ? __('Built in :seconds s.', ['seconds' => $last['seconds'] ?? '?'])
            : __('Refreshed in :seconds s.', ['seconds' => $last['seconds'] ?? '?']));
    }
}

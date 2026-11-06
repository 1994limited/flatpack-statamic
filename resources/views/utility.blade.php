{{-- Statamic 6: rendered inside the Control Panel's own page, as a Vue template. --}}
<div class="max-w-page mx-auto">
    <ui-header title="{{ __('Flatpack') }}" icon="cache">
        @if ($actions)
        <div class="flex gap-2">
            <form method="POST" action="{{ $refreshUrl }}">
                @csrf
                <ui-button type="submit" text="{{ __('Refresh') }}" @if(! $enabled || ! $built || $running) disabled @endif></ui-button>
            </form>
            <form method="POST" action="{{ $buildUrl }}">
                @csrf
                <ui-button type="submit" variant="primary" text="{{ $built ? __('Rebuild') : __('Build') }}" @if(! $enabled || $running) disabled @endif></ui-button>
            </form>
        </div>
        @endif
    </ui-header>

    @if ($sharedStore)
        <ui-alert class="mb-6" text="{{ __('Without Flatpack, the Stache would be in your :store cache, which every server of a site can share. Flatpack keeps it on this server\'s disk instead. On one server that\'s fine. On several, a Control Panel save only shows on the server that took it, until each one runs flatpack:refresh or a deploy rebuilds it.', ['store' => $sharedStore['store'] === $sharedStore['driver'] ? $sharedStore['store'] : $sharedStore['store'].' ('.$sharedStore['driver'].')']) }}"></ui-alert>
    @endif

    @if (! $enabled)
        <ui-alert variant="warning" class="mb-6" text="{{ __('Flatpack is turned off (FLATPACK_ENABLED=false), so Statamic is using its own Stache cache.') }}"></ui-alert>
    @elseif ($running)
        <ui-alert class="mb-6" text="{{ __('A :action started :ago and is still running. Reload to check. If it never finishes, this clears itself after 15 minutes.', ['action' => $running['action'], 'ago' => $running['ago']]) }}"></ui-alert>
    @elseif (! $built)
        <ui-alert variant="warning" class="mb-6" text="{{ __('Nothing has been built yet. Build now, or run php please flatpack:build in your deploy.') }}"></ui-alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <ui-panel class="h-full flex flex-col">
            <ui-panel-header class="flex items-center justify-between min-h-10">
                <ui-heading text="{{ __('Compiled content') }}"></ui-heading>
            </ui-panel-header>
            <ui-card class="flex-1">
                <ui-description text="{{ __('Your content, compiled into PHP files OPcache serves from memory. It is a cache: your content files stay the source of truth.') }}"></ui-description>
                <div class="flex flex-wrap gap-2 mt-3">
                    <ui-badge prepend="{{ __('Last built') }}">{{ $builtAgo ?? __('Never') }}@if ($builtVia === 'stache:warm') ({{ __('by a Stache warm') }})@endif</ui-badge>
                    @if ($refreshedAgo)
                        <ui-badge prepend="{{ __('Last refreshed') }}">{{ $refreshedAgo }}</ui-badge>
                    @endif
                    <ui-badge prepend="{{ __('Entry files') }}">{{ $files['shards'] }}</ui-badge>
                    <ui-badge prepend="{{ __('Indexes') }}">{{ $files['indexes'] }}</ui-badge>
                    <ui-badge prepend="{{ __('Large fields pre-parsed') }}">{{ $files['bodies'] }}</ui-badge>
                    <ui-badge prepend="{{ __('Size') }}">{{ $size }}</ui-badge>
                    <ui-badge prepend="{{ __('Path') }}">{{ $path }}</ui-badge>
                </div>
            </ui-card>
        </ui-panel>

        <ui-panel class="h-full flex flex-col">
            <ui-panel-header class="flex items-center justify-between min-h-10">
                <ui-heading text="{{ __('Changed since the last build') }}"></ui-heading>
            </ui-panel-header>
            <ui-card class="flex-1">
                @if (! $pending['manifest'])
                    <ui-description text="{{ __('Build once to start tracking changes to your content files.') }}"></ui-description>
                @elseif ($pending['count'] === 0)
                    <ui-description text="{{ __('Up to date. No content files have changed on disk.') }}"></ui-description>
                @else
                    <ui-description text="{{ trans_choice(':count content file has changed on disk. Refresh to pick it up.|:count content files have changed on disk. Refresh to pick them up.', $pending['count']) }}"></ui-description>
                    <ul class="mt-3 text-xs font-mono space-y-1">
                        @foreach ($pending['paths'] as $p)
                            <li class="truncate">{{ $p }}</li>
                        @endforeach
                        @if ($pending['count'] > count($pending['paths']))
                            <li>{{ __('and :count more', ['count' => $pending['count'] - count($pending['paths'])]) }}</li>
                        @endif
                    </ul>
                @endif
            </ui-card>
        </ui-panel>

        <ui-panel class="h-full flex flex-col">
            <ui-panel-header class="flex items-center justify-between min-h-10">
                <ui-heading text="{{ __('Last run') }}"></ui-heading>
            </ui-panel-header>
            <ui-card class="flex-1">
                @if (! $lastRun)
                    <ui-description text="{{ __('Nothing run from here yet. Builds run from your deploy show under Compiled content.') }}"></ui-description>
                @elseif ($lastRun['error'])
                    <ui-description text="{{ __('The :action failed :ago:', ['action' => $lastRun['action'], 'ago' => $lastRun['ago']]) }}"></ui-description>
                    <pre class="mt-3 text-xs whitespace-pre-wrap">{{ $lastRun['error'] }}</pre>
                @else
                    <div class="flex flex-wrap gap-2">
                        <ui-badge prepend="{{ ucfirst($lastRun['action']) }}">{{ $lastRun['ago'] }}</ui-badge>
                        <ui-badge prepend="{{ __('Took') }}">{{ $lastRun['seconds'] }} s</ui-badge>
                        @if ($lastRun['action'] === 'refresh')
                            <ui-badge prepend="{{ __('Files') }}">{{ $lastRun['changed'] }}</ui-badge>
                        @endif
                    </div>
                @endif
            </ui-card>
        </ui-panel>

        <ui-panel class="h-full flex flex-col">
            <ui-panel-header class="flex items-center justify-between min-h-10">
                <ui-heading text="{{ __('Server') }}"></ui-heading>
            </ui-panel-header>
            <ui-card class="flex-1">
                <div class="flex flex-wrap gap-2">
                    <ui-badge prepend="OPcache" color="{{ $opcache ? 'green' : 'amber' }}">{{ $opcache ? __('On') : __('Off') }}</ui-badge>
                    <ui-badge prepend="pcntl" color="{{ $pcntl ? 'green' : 'default' }}">{{ $pcntl ? __('Available') : __('Not here') }}</ui-badge>
                </div>
                <ui-description class="mt-3" text="{{ $opcache ? __('OPcache serves compiled content from memory.') : __('Turn OPcache on for web requests: Flatpack works without it, but much of the speed comes from it.') }} {{ __('pcntl only matters on the command line, where builds use it to pre-parse large fields in parallel.') }}"></ui-description>
            </ui-card>
        </ui-panel>
    </div>
</div>

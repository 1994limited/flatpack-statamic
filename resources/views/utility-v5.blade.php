@php use function Statamic\trans as __; @endphp

@extends('statamic::layout')
@section('title', __('Flatpack'))

@section('content')

    <header class="mb-6">
        @include('statamic::partials.breadcrumb', [
            'url' => cp_route('utilities.index'),
            'title' => __('Utilities')
        ])
        <div class="flex items-center justify-between">
            <h1>{{ __('Flatpack') }}</h1>
            @if ($actions)
            <div class="flex">
                <form method="POST" action="{{ $refreshUrl }}" class="rtl:ml-2 ltr:mr-2">
                    @csrf
                    <button class="btn" @if(! $enabled || ! $built || $running) disabled @endif>{{ __('Refresh') }}</button>
                </form>
                <form method="POST" action="{{ $buildUrl }}">
                    @csrf
                    <button class="btn-primary" @if(! $enabled || $running) disabled @endif>{{ $built ? __('Rebuild') : __('Build') }}</button>
                </form>
            </div>
            @endif
        </div>
    </header>

    @if ($sharedStore)
        <div class="card mb-6 text-sm">{{ __('Without Flatpack, the Stache would be in your :store cache, which every server of a site can share. Flatpack keeps it on this server\'s disk instead. On one server that\'s fine. On several, a Control Panel save only shows on the server that took it, until each one runs flatpack:refresh or a deploy rebuilds it.', ['store' => $sharedStore['store'] === $sharedStore['driver'] ? $sharedStore['store'] : $sharedStore['store'].' ('.$sharedStore['driver'].')']) }}</div>
    @endif

    @if ($paused)
        <div class="card mb-6 text-sm text-red-500">{{ $paused }}</div>
    @elseif (! $enabled)
        <div class="card mb-6 text-sm">{{ __('Flatpack is turned off (FLATPACK_ENABLED=false), so Statamic is using its own Stache cache.') }}</div>
    @elseif ($running)
        <div class="card mb-6 text-sm">{{ __('A :action started :ago and is still running. Reload to check. If it never finishes, this clears itself after 15 minutes.', ['action' => $running['action'], 'ago' => $running['ago']]) }}</div>
    @elseif (! $built)
        <div class="card mb-6 text-sm">{{ __('Nothing has been built yet. Build now, or run php please flatpack:build in your deploy.') }}</div>
    @endif

    <div class="card p-0">
        <div class="p-4">
            <h2 class="font-bold">{{ __('Compiled content') }}</h2>
            <p class="text-gray dark:text-dark-150 text-sm my-2">{{ __('Your content, compiled into PHP files OPcache serves from memory. It is a cache: your content files stay the source of truth.') }}</p>
            <div class="text-sm text-gray dark:text-dark-150 flex flex-wrap">
                @foreach ([
                    __('Last built') => ($builtAgo ?? __('Never')).($builtVia === 'stache:warm' ? ' ('.__('by a Stache warm').')' : ''),
                    __('Last refreshed') => $refreshedAgo ?? __('Not since'),
                    __('Entry files') => $files['shards'],
                    __('Indexes') => $files['indexes'],
                    __('Large fields pre-parsed') => $files['bodies'],
                    __('Size') => $size,
                    __('Path') => $path,
                ] as $label => $value)
                    <div class="rtl:ml-4 ltr:mr-4 mb-1 badge-pill-sm"><span class="text-gray-800 dark:text-dark-150 font-medium">{{ $label }}:</span> {{ $value }}</div>
                @endforeach
            </div>
        </div>

        <div class="p-4 bg-gray-200 dark:bg-dark-700 border-t dark:border-dark-900">
            <h2 class="font-bold">{{ __('Changed since the last build') }}</h2>
            <div class="text-gray dark:text-dark-150 text-sm my-2">
                @if (! $pending['manifest'])
                    {{ __('Build once to start tracking changes to your content files.') }}
                @elseif ($pending['count'] === 0)
                    {{ __('Up to date. No content files have changed on disk.') }}
                @else
                    <p>{{ trans_choice(':count content file has changed on disk. Refresh to pick it up.|:count content files have changed on disk. Refresh to pick them up.', $pending['count']) }}</p>
                    <ul class="mt-2 text-xs font-mono">
                        @foreach ($pending['paths'] as $p)
                            <li class="truncate">{{ $p }}</li>
                        @endforeach
                        @if ($pending['count'] > count($pending['paths']))
                            <li>{{ __('and :count more', ['count' => $pending['count'] - count($pending['paths'])]) }}</li>
                        @endif
                    </ul>
                @endif
            </div>
        </div>

        <div class="p-4 border-t dark:border-dark-900">
            <h2 class="font-bold">{{ __('Last run') }}</h2>
            <div class="text-gray dark:text-dark-150 text-sm my-2">
                @if (! $lastRun)
                    {{ __('Nothing run from here yet. Builds run from your deploy show above.') }}
                @elseif ($lastRun['error'])
                    {{ __('The :action failed :ago:', ['action' => $lastRun['action'], 'ago' => $lastRun['ago']]) }}
                    <pre class="mt-2 text-xs whitespace-pre-wrap">{{ $lastRun['error'] }}</pre>
                @else
                    {{ ucfirst($lastRun['action']) }} {{ $lastRun['ago'] }}, {{ __('took') }} {{ $lastRun['seconds'] }} s
                    @if ($lastRun['action'] === 'refresh')
                        ({{ trans_choice(':count file|:count files', $lastRun['changed']) }})
                    @endif
                @endif
            </div>
        </div>

        <div class="p-4 bg-gray-200 dark:bg-dark-700 border-t dark:border-dark-900">
            <h2 class="font-bold">{{ __('Server') }}</h2>
            <div class="text-sm text-gray dark:text-dark-150 flex my-2">
                <div class="rtl:ml-4 ltr:mr-4 badge-pill-sm"><span class="text-gray-800 dark:text-dark-150 font-medium">OPcache:</span> {{ $opcache ? __('On') : __('Off') }}</div>
                <div class="badge-pill-sm"><span class="text-gray-800 dark:text-dark-150 font-medium">pcntl:</span> {{ $pcntl ? __('Available') : __('Not here') }}</div>
            </div>
            <p class="text-gray dark:text-dark-150 text-sm">{{ $opcache ? __('OPcache serves compiled content from memory.') : __('Turn OPcache on for web requests: Flatpack works without it, but much of the speed comes from it.') }} {{ __('pcntl only matters on the command line, where builds use it to pre-parse large fields in parallel.') }}</p>
        </div>
    </div>

@endsection

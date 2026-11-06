<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Turn Flatpack off and Statamic goes back to its own Stache cache, with
    | nothing else to change. Your content files are never touched either way.
    |
    */

    'enabled' => env('FLATPACK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Path
    |--------------------------------------------------------------------------
    |
    | Where the compiled content lives. It is a cache: safe to delete, and
    | rebuilt by `php please flatpack:build`. Keep it out of version control.
    |
    */

    'path' => env('FLATPACK_PATH', storage_path('statamic/flatpack')),

    /*
    |--------------------------------------------------------------------------
    | Lazy limit
    |--------------------------------------------------------------------------
    |
    | Top-level fields larger than this many bytes of YAML (page builders, long
    | Bard fields) are not parsed until something reads them. Listings, URIs,
    | navs and queries never need them, so most requests skip that work.
    | Set to 0 to parse everything up front.
    |
    */

    'lazy_limit' => (int) env('FLATPACK_LAZY_LIMIT', 2048),

    /*
    |--------------------------------------------------------------------------
    | Shard buckets
    |--------------------------------------------------------------------------
    |
    | Entries are stored in this many files per collection. More buckets means
    | smaller files to rewrite on save; fewer means fewer files to load.
    |
    */

    'shard_buckets' => (int) env('FLATPACK_SHARD_BUCKETS', 32),

    /*
    |--------------------------------------------------------------------------
    | Build workers
    |--------------------------------------------------------------------------
    |
    | `flatpack:build` also parses the large fields ahead of time so the first
    | visit to a page doesn't have to. With the pcntl extension (available on
    | the command line on Forge and Laravel Cloud) this runs in parallel.
    | Set to 0 to skip it and parse large fields on first read instead.
    |
    */

    'build_workers' => (int) env('FLATPACK_BUILD_WORKERS', 4),

    /*
    |--------------------------------------------------------------------------
    | Control Panel actions
    |--------------------------------------------------------------------------
    |
    | The Build and Refresh buttons on Utilities -> Flatpack. They only ever
    | update the server that handles the click, so on a site with more than
    | one server (several Forge servers, Laravel Cloud replicas) turn them
    | off and build in your deploy instead. The page still shows the status.
    |
    */

    'control_panel_actions' => (bool) env('FLATPACK_CP_ACTIONS', true),

    /*
    |--------------------------------------------------------------------------
    | Shared Stache warning
    |--------------------------------------------------------------------------
    |
    | Flatpack keeps the Stache on each server's own disk. If your cache store
    | is one every server shares (Redis, Valkey, a database), Flatpack says so
    | in `flatpack:status` and the Control Panel. On a single server that's
    | fine, and you can turn the notice off here.
    |
    */

    'warn_shared_stache' => (bool) env('FLATPACK_WARN_SHARED_STACHE', true),

];

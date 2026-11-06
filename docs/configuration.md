# Configuration

Flatpack works with no configuration. To change anything, publish the config file:

```bash
php artisan vendor:publish --tag=flatpack-config
```

| Option | Env | Default | What it does |
|---|---|---|---|
| `enabled` | `FLATPACK_ENABLED` | `true` | Off puts Statamic back on its own Stache cache. `flatpack:build` and `flatpack:refresh` then warm Statamic's own Stache, so deploy scripts don't need changing. |
| `path` | `FLATPACK_PATH` | `storage/statamic/flatpack` | Where the compiled files go. A cache: safe to delete. |
| `lazy_limit` | `FLATPACK_LAZY_LIMIT` | `2048` | Top-level fields bigger than this (bytes of YAML) are parsed on first read. `0` parses everything up front. |
| `shard_buckets` | `FLATPACK_SHARD_BUCKETS` | `32` | Files per collection. More means smaller rewrites on save. |
| `control_panel_actions` | `FLATPACK_CP_ACTIONS` | `true` | The Build and Refresh buttons on the Flatpack page. Turn off on sites with more than one server: a click only updates the server that handled it. |
| `warn_shared_stache` | `FLATPACK_WARN_SHARED_STACHE` | `true` | Say when Flatpack has replaced a Stache that would have been in a shared cache (Redis, Valkey, a database). Turn off on a single server. |
| `build_workers` | `FLATPACK_BUILD_WORKERS` | `4` | Parallel workers that pre-parse large fields during a build. `0` skips it. Needs pcntl; without it, the build does them in one process. |

Flatpack sets `statamic.stache.cache_store` itself, so leave that unset.

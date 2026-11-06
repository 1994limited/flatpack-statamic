<?php

namespace NineteenNinetyFour\Flatpack;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Facade;
use NineteenNinetyFour\Flatpack\Commands\Build;
use NineteenNinetyFour\Flatpack\Commands\Refresh;
use NineteenNinetyFour\Flatpack\Commands\Status;
use NineteenNinetyFour\Flatpack\Http\Controllers\UtilityController;
use NineteenNinetyFour\Flatpack\Listeners\RecordManifest;
use Statamic\CP\Utilities\Utility as UtilityItem;
use Statamic\Events\StacheWarmed;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public const DRIVER = 'flatpack';

    protected $commands = [
        Build::class,
        Refresh::class,
        Status::class,
    ];

    protected $listen = [
        StacheWarmed::class => [RecordManifest::class],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/flatpack.php', 'flatpack');

        if (! config('flatpack.enabled', true)) {
            return;
        }

        Flatpack::configure((string) config('flatpack.path'), (int) config('flatpack.lazy_limit', 2048));
        FlatpackStore::$buckets = max(1, (int) config('flatpack.shard_buckets', 32));

        // What the Stache would have used without Flatpack, so we can say when Flatpack has
        // moved it off a store every server shares (Redis, Valkey, a database...) onto local disk.
        $replaced = config('statamic.stache.cache_store') ?: config('cache.default');
        Flatpack::$replacedStore = ['store' => $replaced, 'driver' => config("cache.stores.{$replaced}.driver")];

        config([
            'cache.stores.'.self::DRIVER => ['driver' => self::DRIVER, 'path' => Flatpack::$path],
            'statamic.stache.cache_store' => self::DRIVER,
        ]);

        // Registered as soon as the cache manager exists, so the driver is there
        // before anything (Statamic included) asks the Stache for its store.
        $this->callAfterResolving('cache', function (CacheManager $cache) {
            $cache->extend(self::DRIVER, fn ($app, $config) => $cache->repository(new FlatpackStore($config['path'])));
        });

        // After every provider has registered: Composer discovers this addon before
        // statamic/cms, so Statamic's own bindings would otherwise replace these.
        $this->app->booting(function ($app) {
            $app->bind(\Statamic\Contracts\Entries\Entry::class, FlatpackEntry::class);
            $app->bind(\Statamic\Stache\Traverser::class, Stache\FastTraverser::class);
            $app->singleton(\Statamic\Yaml\Yaml::class, fn () => new LeanYaml(new Yaml\SymfonyFast));
            Facade::clearResolvedInstance(\Statamic\Yaml\Yaml::class);
        });
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/flatpack.php' => config_path('flatpack.php'),
        ], 'flatpack-config');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'flatpack');
        $this->registerUtility();

        if (! config('flatpack.enabled', true)) {
            return;
        }

        // Writes are buffered for the request and written once at the end.
        $this->app->terminating(fn () => FlatpackStore::persistAll());
        register_shutdown_function(fn () => FlatpackStore::persistAll());
    }

    private function registerUtility(): void
    {
        // Statamic 6 renders a utility's Blade view inside its own page as a Vue template, with
        // the ui-* components; Statamic 5 renders it as a whole Blade page on its layout.
        $view = method_exists(UtilityItem::class, 'inertia') ? 'flatpack::utility' : 'flatpack::utility-v5';

        Utility::extend(function () use ($view) {
            Utility::register('flatpack')
                ->title(__('Flatpack'))
                ->icon(file_get_contents(__DIR__.'/../resources/brand/nav-icon.svg'))
                ->description(__('See what Flatpack has compiled and what has changed since, and build or refresh it.'))
                ->view($view, fn () => UtilityController::data())
                ->routes(function ($router) {
                    $router->post('build', [UtilityController::class, 'build'])->name('build');
                    $router->post('refresh', [UtilityController::class, 'refresh'])->name('refresh');
                });
        });
    }
}

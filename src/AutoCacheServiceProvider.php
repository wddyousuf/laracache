<?php

namespace Wddyousuf\AutoCache;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\ServiceProvider;
use Wddyousuf\AutoCache\Console\ClearCommand;
use Wddyousuf\AutoCache\Console\FlushCommand;
use Wddyousuf\AutoCache\Console\StatsCommand;
use Wddyousuf\AutoCache\Console\WarmCommand;
use Wddyousuf\AutoCache\Listeners\FlushCacheOnPivotWrite;

class AutoCacheServiceProvider extends ServiceProvider
{
    /**
     * Register package services and merge the default configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/autocache.php', 'autocache');

        $this->app->singleton('autocache', fn () => new CacheManager);
        $this->app->alias('autocache', CacheManager::class);
    }

    /**
     * Bootstrap package services (publishing config, registering commands).
     */
    public function boot(): void
    {
        $this->registerOctaneListeners();
        $this->registerPivotInvalidation();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/autocache.php' => $this->app->configPath('autocache.php'),
            ], 'autocache-config');

            $this->commands([
                FlushCommand::class,
                ClearCommand::class,
                WarmCommand::class,
                StatsCommand::class,
            ]);
        }
    }

    /**
     * Reset process-static state at the start of each Octane request/task/tick
     * so a long-lived worker never carries flush state between requests.
     */
    /**
     * Watch the query stream for writes to configured pivot tables and flush
     * the cacheable models whose reads depend on them. Only registered when a
     * pivot map is configured, so there is zero query-stream overhead otherwise.
     */
    protected function registerPivotInvalidation(): void
    {
        if (! config('autocache.enabled', true)) {
            return;
        }

        if (! config('autocache.pivot_invalidation.enabled', true)) {
            return;
        }

        if (empty(config('autocache.pivot_invalidation.map', []))) {
            return;
        }

        $this->app['events']->listen(QueryExecuted::class, FlushCacheOnPivotWrite::class);
    }

    protected function registerOctaneListeners(): void
    {
        // String class names (not ::class) so static analysis doesn't require
        // the optional Octane package to be installed.
        $events = [
            'Laravel\Octane\Events\RequestReceived',
            'Laravel\Octane\Events\TaskReceived',
            'Laravel\Octane\Events\TickReceived',
        ];

        foreach ($events as $event) {
            if (class_exists($event)) {
                $this->app['events']->listen($event, fn () => CacheManager::resetState());
            }
        }
    }
}

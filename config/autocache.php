<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Caching Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch for the whole package. When false, every cacheable model
    | falls straight through to the database.
    |
    */

    'enabled' => env('AUTOCACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | Which of your "cache.stores" entries AutoCache should use. Null uses the
    | application default. Any store works — invalidation falls back to a
    | version counter when the store does not support tags.
    |
    */

    'store' => env('AUTOCACHE_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Time To Live
    |--------------------------------------------------------------------------
    |
    | Default lifetime, in seconds, for a cached result. Null caches forever
    | (results are still flushed automatically on writes).
    |
    */

    'ttl' => env('AUTOCACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | TTL Jitter
    |--------------------------------------------------------------------------
    |
    | Fraction (0.0–1.0) by which each TTL is randomly spread, so a burst of
    | keys written together do not all expire on the same tick (thundering
    | herd). 0.1 means "±10%". Set to 0 to disable.
    |
    */

    'ttl_jitter' => env('AUTOCACHE_TTL_JITTER', 0.1),

    /*
    |--------------------------------------------------------------------------
    | Key Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('AUTOCACHE_PREFIX', 'autocache'),

    /*
    |--------------------------------------------------------------------------
    | Tag-Based Invalidation
    |--------------------------------------------------------------------------
    |
    | "auto"  — use cache tags when the store supports them, else a version
    |           counter. Tags allow immediate, targeted flushing.
    | true    — same as auto (tags when supported).
    | false   — always use the version counter.
    |
    */

    'use_tags' => env('AUTOCACHE_USE_TAGS', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Stampede Protection
    |--------------------------------------------------------------------------
    |
    | Seconds to hold a lock while a missed key is (re)computed, so a flood of
    | concurrent requests doesn't all hit the database at once. Requires a
    | lock-capable store (redis, memcached, database, array, file). 0 disables.
    |
    */

    'lock_for' => env('AUTOCACHE_LOCK_FOR', 10),

    /*
    |--------------------------------------------------------------------------
    | Caching Mode
    |--------------------------------------------------------------------------
    |
    | "auto"   — cache every read automatically (the default).
    | "opt-in" — cache only queries that explicitly call ->cache() / ->cacheFor()
    |            (or Model::cache() / Model::cacheFor()).
    |
    | Individual models can override this with a $cacheMode property.
    |
    */

    'mode' => env('AUTOCACHE_MODE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Row-Level Caching
    |--------------------------------------------------------------------------
    |
    | Cache canonical `find($id)` lookups under a stable per-row key, so a
    | single row's cache survives writes to other rows (version stores only).
    |
    */

    'row_cache' => env('AUTOCACHE_ROW_CACHE', true),

    /*
    |--------------------------------------------------------------------------
    | Stale-While-Revalidate
    |--------------------------------------------------------------------------
    |
    | Seconds of "stale grace" after a key's TTL during which the old value is
    | served instantly while a fresh one is computed in the background. Needs
    | Laravel 11+ (Cache::flexible). 0 disables. Skipped for models with a
    | max_rows cap, where the size guard must run on every store.
    |
    */

    'swr' => env('AUTOCACHE_SWR', 0),

    /*
    |--------------------------------------------------------------------------
    | Maximum Cacheable Rows
    |--------------------------------------------------------------------------
    |
    | Result sets larger than this are executed but never stored, to protect
    | the cache store's memory. Null means "no limit".
    |
    */

    'max_rows' => env('AUTOCACHE_MAX_ROWS', null),

    /*
    |--------------------------------------------------------------------------
    | Volatile Query Markers
    |--------------------------------------------------------------------------
    |
    | Queries whose SQL contains any of these substrings are never cached,
    | because their result would otherwise be frozen at first execution.
    |
    */

    'volatile_patterns' => [
        'now()', 'current_timestamp', 'rand(', 'random(', 'uuid()', 'newid()',
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    |
    | When true, AutoCache tallies hit/miss counters (global and per-model)
    | that you can read via `AutoCache::stats()` or `php artisan autocache:stats`.
    |
    */

    'stats' => env('AUTOCACHE_STATS', false),

    /*
    |--------------------------------------------------------------------------
    | Registered Models
    |--------------------------------------------------------------------------
    |
    | Cacheable models are auto-registered once booted, but console commands
    | (clear/warm) may run before any model boots. List them here so those
    | commands can find them.
    |
    */

    'models' => [
        // App\Models\Post::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pivot / Many-to-Many Invalidation
    |--------------------------------------------------------------------------
    |
    | AutoCache only invalidates from writes issued through a cacheable model's
    | own query builder. Many-to-many writes — $post->tags()->sync()/attach()/
    | detach() — are bare pivot statements that carry no model, so a cached
    | relation query (`$post->tags`, or a query joining the pivot) keeps serving
    | stale rows until its TTL expires. This is easy to miss and, when the pivot
    | gates authorization, unsafe.
    |
    | Map each pivot table to the cacheable models whose reads it can change —
    | typically both sides of the relation (whichever are cacheable). When the
    | map is non-empty, AutoCache watches the query stream and flushes those
    | models on any write to a listed pivot, wherever the write originates.
    |
    */

    'pivot_invalidation' => [

        'enabled' => env('AUTOCACHE_PIVOT_INVALIDATION', true),

        'map' => [
            // 'post_tag' => [App\Models\Post::class, App\Models\Tag::class],
        ],

    ],

];

<?php

namespace Wddyousuf\AutoCache\Traits;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Wddyousuf\AutoCache\CacheManager;
use Wddyousuf\AutoCache\Events\CacheFlushed;
use Wddyousuf\AutoCache\Events\CacheHit;
use Wddyousuf\AutoCache\Events\CacheMissed;
use Wddyousuf\AutoCache\Query\CachedBuilder;
use Wddyousuf\AutoCache\Query\CachedQueryBuilder;

/**
 * Add automatic, self-invalidating query caching to an Eloquent model.
 *
 * Reads are cached transparently; any write — including bulk, raw and
 * event-suppressing "quiet" writes — flushes the model's cache. Invalidation
 * uses cache tags when the store supports them, and a per-model version
 * counter otherwise, so it works with every cache driver.
 *
 * Per-model overrides (declare as properties):
 *   protected $cacheStore    = 'redis';       // store name
 *   protected $cacheTtl      = 600;           // seconds; null = forever
 *   protected $cacheEnabled  = true;          // toggle caching for this model
 *   protected $cacheMode     = 'opt-in';      // 'auto' or 'opt-in' for this model
 *   protected $cacheTags     = ['catalog'];   // extra tags (tag mode)
 *   protected $cacheMaxRows  = 5000;          // skip caching larger results
 *   protected $flushRelated  = ['comments'];  // relations/models to co-flush
 *
 * @method static \Wddyousuf\AutoCache\Query\CachedBuilder<static> query()
 *
 * @phpstan-require-extends Model
 */
trait Cacheable
{
    /** Memoized tag support per cache store name, keyed for the whole process. */
    protected static array $autocacheTagSupport = [];

    /**
     * Boot the trait: register the model so console commands can find it.
     */
    public static function bootCacheable(): void
    {
        CacheManager::register(static::class);
    }

    /**
     * Use our cache-aware Eloquent builder for this model.
     *
     * @param  QueryBuilder  $query
     * @return CachedBuilder<static>
     */
    public function newEloquentBuilder($query): CachedBuilder
    {
        return new CachedBuilder($query);
    }

    /**
     * Back every query with a cache-aware base builder bound to this model.
     */
    protected function newBaseQueryBuilder(): CachedQueryBuilder
    {
        $connection = $this->getConnection();

        return (new CachedQueryBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor()
        ))->setCacheModel($this);
    }

    /**
     * Start a query with caching disabled: Post::withoutCache()->get().
     *
     * @return CachedBuilder<static>
     */
    public static function withoutCache(): CachedBuilder
    {
        return static::query()->withoutCache();
    }

    /**
     * Start a query with a per-query TTL: Post::cacheFor(60)->get().
     *
     * @return CachedBuilder<static>
     */
    public static function cacheFor(?int $ttl): CachedBuilder
    {
        return static::query()->cacheFor($ttl);
    }

    /**
     * Explicitly opt a query into caching (for the opt-in mode):
     * Post::cache()->where(...)->get().
     *
     * @return CachedBuilder<static>
     */
    public static function cache(): CachedBuilder
    {
        return static::query()->cache();
    }

    // ---------------------------------------------------------------------
    // Configuration resolution
    // ---------------------------------------------------------------------

    /**
     * Read a model-level override property without tripping Eloquent's magic
     * attribute/relationship resolution (which fires for undeclared names).
     */
    protected function cacheOption(string $property, mixed $default): mixed
    {
        return property_exists($this, $property) ? $this->{$property} : $default;
    }

    public function cacheIsEnabled(): bool
    {
        return (bool) config('autocache.enabled', true)
            && (bool) $this->cacheOption('cacheEnabled', true);
    }

    /**
     * The caching mode for this model: 'auto' (cache every read) or 'opt-in'
     * (cache only ->cache()/->cacheFor() queries). Overrides the global mode.
     */
    public function cacheMode(): string
    {
        return (string) $this->cacheOption('cacheMode', config('autocache.mode', 'auto'));
    }

    /**
     * The underlying (never tagged) store — used for the version counter,
     * stats and locks.
     */
    public function rawCacheStore(): CacheRepository
    {
        return Cache::store($this->cacheOption('cacheStore', config('autocache.store')));
    }

    /**
     * The store used for query results — tagged when tag mode is active.
     */
    public function cacheStore(): CacheRepository
    {
        $store = $this->rawCacheStore();

        return $this->cacheUsesTags() ? $store->tags($this->cacheTags()) : $store;
    }

    public function cacheTags(): array
    {
        return array_values(array_unique(array_merge(
            [$this->cachePrefix()],
            (array) $this->cacheOption('cacheTags', [])
        )));
    }

    /**
     * Whether this model invalidates via cache tags (vs. a version counter).
     */
    public function cacheUsesTags(): bool
    {
        if (config('autocache.use_tags', 'auto') === false) {
            return false;
        }

        return $this->storeSupportsTags();
    }

    protected function storeSupportsTags(): bool
    {
        $name = $this->cacheOption('cacheStore', config('autocache.store'));
        $memo = $name ?? '@default';

        if (! array_key_exists($memo, static::$autocacheTagSupport)) {
            try {
                Cache::store($name)->tags(['autocache:probe']);
                static::$autocacheTagSupport[$memo] = true;
            } catch (\BadMethodCallException) {
                static::$autocacheTagSupport[$memo] = false;
            }
        }

        return static::$autocacheTagSupport[$memo];
    }

    public function cacheTtl(): ?int
    {
        $ttl = $this->cacheOption('cacheTtl', config('autocache.ttl'));

        return $ttl === null ? null : (int) $ttl;
    }

    /**
     * The TTL actually written to the store, spread by the configured jitter.
     */
    protected function cacheTtlForStorage(): ?int
    {
        $ttl = $this->cacheTtl();

        if ($ttl === null) {
            return null;
        }

        $jitter = (float) config('autocache.ttl_jitter', 0.0);
        $delta = (int) round($ttl * max(0.0, $jitter));

        return $delta > 0 ? $ttl + random_int(-$delta, $delta) : $ttl;
    }

    public function cacheMaxRows(): ?int
    {
        $max = $this->cacheOption('cacheMaxRows', config('autocache.max_rows'));

        return $max === null ? null : (int) $max;
    }

    public function cachePrefix(): string
    {
        return config('autocache.prefix', 'autocache').':'.$this->getTable();
    }

    public function cacheVersionKey(): string
    {
        return $this->cachePrefix().':version';
    }

    /**
     * Current version for this model. Baked into every key (in version mode)
     * so bumping it on a write invalidates all prior results.
     */
    public function getCacheVersion(): int
    {
        return (int) $this->rawCacheStore()->rememberForever(
            $this->cacheVersionKey(),
            fn () => 1
        );
    }

    /**
     * Build the cache key for a specific query against this model.
     */
    public function cacheKeyFor(QueryBuilder $query, array $columns = ['*'], string $type = 'get'): string
    {
        $signature = md5(serialize([
            static::class,
            $query->getConnection()->getName(),
            $type,
            $query->toSql(),
            $query->getBindings(),
            $columns,
        ]));

        $key = $this->cachePrefix();

        if (! $this->cacheUsesTags()) {
            $key .= ':v'.$this->getCacheVersion();
        }

        return $key.':'.$signature;
    }

    /**
     * Build the cache key for a user-supplied fixed name (->cacheKey('...')).
     * The name stays stable across queries, but the model version is still
     * baked in (version mode) so writes invalidate it like any derived key.
     */
    public function cacheKeyForCustom(string $name, string $type = 'select'): string
    {
        $key = $this->cachePrefix();

        if (! $this->cacheUsesTags()) {
            $key .= ':v'.$this->getCacheVersion();
        }

        return $key.':custom:'.$type.':'.$name;
    }

    // ---------------------------------------------------------------------
    // Read execution (called by CachedQueryBuilder)
    // ---------------------------------------------------------------------

    /**
     * Fetch a value from cache or compute and store it, with hit/miss events,
     * stampede protection and a result-size guard.
     *
     * $hasTtlOverride distinguishes "no override" from an explicit override to
     * null (cacheFor(null) = cache forever).
     */
    public function rememberInCache(string $key, Closure $callback, ?int $ttlOverride = null, bool $hasTtlOverride = false): mixed
    {
        $store = $this->cacheStore();
        $miss = new \stdClass;

        $cached = $store->get($key, $miss);
        $isHit = $cached !== $miss;

        $this->recordCacheStat($isHit ? 'hits' : 'misses');
        event($isHit ? new CacheHit($this, $key) : new CacheMissed($this, $key));

        $ttl = ($hasTtlOverride || $ttlOverride !== null)
            ? $ttlOverride
            : $this->cacheTtlForStorage();

        // Stale-while-revalidate (Laravel 11+): serve the value instantly and
        // refresh it in the background once it passes the fresh window. Only
        // meaningful with a finite TTL and a store that supports flexible().
        // flexible() stores unconditionally, so a model with a max_rows cap
        // must use the standard path where the size guard can apply.
        if ($ttl !== null
            && $this->swrWindow() > 0
            && $this->cacheMaxRows() === null
            && method_exists($store, 'flexible')) {
            return $store->flexible($key, [$ttl, $ttl + $this->swrWindow()], $callback);
        }

        if ($isHit) {
            return $cached;
        }

        $value = $this->computeWithStampedeGuard($store, $key, $callback);

        if ($this->cacheResultTooLarge($value)) {
            return $value;
        }

        $ttl === null
            ? $store->forever($key, $value)
            : $store->put($key, $value, $ttl);

        return $value;
    }

    /**
     * Seconds of stale grace after the fresh TTL (0 = SWR disabled).
     */
    protected function swrWindow(): int
    {
        return max(0, (int) config('autocache.swr', 0));
    }

    /**
     * Compute a missed value, guarding against a stampede of concurrent
     * requests all recomputing at once when the store supports locks.
     */
    protected function computeWithStampedeGuard(CacheRepository $store, string $key, Closure $callback): mixed
    {
        $seconds = (int) config('autocache.lock_for', 0);
        $lockStore = $this->rawCacheStore()->getStore();

        if ($seconds <= 0 || ! $lockStore instanceof LockProvider) {
            return $callback();
        }

        $lock = $lockStore->lock($key.':lock', $seconds);

        if ($lock->get()) {
            try {
                return $callback();
            } finally {
                $lock->release();
            }
        }

        // Another worker holds the lock; wait for it, then read what it wrote.
        try {
            $lock->block($seconds);
            $lock->release();
        } catch (LockTimeoutException) {
            return $callback();
        }

        $miss = new \stdClass;
        $existing = $store->get($key, $miss);

        return $existing === $miss ? $callback() : $existing;
    }

    protected function cacheResultTooLarge(mixed $value): bool
    {
        $max = $this->cacheMaxRows();

        if ($max === null) {
            return false;
        }

        if (is_array($value) || $value instanceof \Countable) {
            return count($value) > $max;
        }

        return false;
    }

    protected function recordCacheStat(string $type): void
    {
        if (! config('autocache.stats', false)) {
            return;
        }

        $store = $this->rawCacheStore();

        foreach (["autocache:stats:{$type}", $this->cachePrefix().":stats:{$type}"] as $statKey) {
            try {
                $store->increment($statKey);
            } catch (\Throwable) {
                // Store cannot increment; stats are best-effort.
            }
        }
    }

    // ---------------------------------------------------------------------
    // Invalidation
    // ---------------------------------------------------------------------

    /**
     * Flush everything cached for this model — query results and row caches
     * alike — plus any related models. Re-entrancy safe.
     */
    public function flushCache(): void
    {
        $this->withFlushGuard(function () {
            $this->purgeAll();
            $this->dispatchFlushed();
        });
    }

    /**
     * Flush only query/aggregate/collection caches, leaving per-row (find)
     * caches intact. Used when a single row changes: lists that may include
     * the row are invalidated, but unrelated rows stay cached.
     */
    public function flushQueriesOnly(): void
    {
        $this->withFlushGuard(function () {
            $this->purgeQueries();
            $this->dispatchFlushed();
        });
    }

    /**
     * Surgically invalidate one row: drop its find() cache and invalidate the
     * query caches (which may list it). Other rows' caches survive. Tag stores
     * fall back to a full flush.
     */
    public function flushForSingleRow(mixed $id): void
    {
        if ($this->cacheUsesTags()) {
            $this->flushCache();

            return;
        }

        $this->withFlushGuard(function () use ($id) {
            $this->purgeQueries();
            $this->flushRow($id);
            $this->dispatchFlushed();
        });
    }

    /**
     * Run a flush closure now (read-after-write consistency) and, when inside
     * a transaction, again after commit (closing the concurrent-reader window;
     * a rollback simply never fires the second run).
     */
    public function runFlush(Closure $flush): void
    {
        $flush();

        $connection = $this->getConnection();

        if (method_exists($connection, 'transactionLevel') && $connection->transactionLevel() > 0) {
            $connection->afterCommit($flush);
        }
    }

    /**
     * Re-entrancy guard shared across models so related-model cascades can't
     * loop back on themselves.
     */
    protected function withFlushGuard(Closure $fn): void
    {
        $class = static::class;

        if (in_array($class, CacheManager::$flushing, true)) {
            return;
        }

        CacheManager::$flushing[] = $class;

        try {
            $fn();
        } finally {
            CacheManager::$flushing = array_values(
                array_diff(CacheManager::$flushing, [$class])
            );
        }
    }

    protected function dispatchFlushed(): void
    {
        event(new CacheFlushed($this));

        foreach ($this->relatedModelsToFlush() as $related) {
            $related->flushCache();
        }
    }

    /**
     * Invalidate query caches AND row caches (tags: flush all).
     */
    protected function purgeAll(): void
    {
        if ($this->cacheUsesTags()) {
            $this->cacheStore()->flush();

            return;
        }

        $this->bumpVersion($this->cacheVersionKey());
        $this->bumpVersion($this->rowCacheVersionKey());
    }

    /**
     * Invalidate query caches only (tags: flush all).
     */
    protected function purgeQueries(): void
    {
        if ($this->cacheUsesTags()) {
            $this->cacheStore()->flush();

            return;
        }

        $this->bumpVersion($this->cacheVersionKey());
    }

    /**
     * Advance a version counter, ensuring it exists first so the increment
     * moves past the version current readers are using (1 -> 2, not null -> 1).
     */
    protected function bumpVersion(string $versionKey): void
    {
        $store = $this->rawCacheStore();
        $store->rememberForever($versionKey, fn () => 1);
        $store->increment($versionKey);
    }

    // ---------------------------------------------------------------------
    // Row-level (per primary key) caching
    // ---------------------------------------------------------------------

    public function rowCacheEnabled(): bool
    {
        return (bool) config('autocache.row_cache', true) && $this->cacheIsEnabled();
    }

    public function rowCacheVersionKey(): string
    {
        return $this->cachePrefix().':rowversion';
    }

    public function getRowCacheVersion(): int
    {
        return (int) $this->rawCacheStore()->rememberForever(
            $this->rowCacheVersionKey(),
            fn () => 1
        );
    }

    protected function rowTag(mixed $id): string
    {
        return $this->cachePrefix().':pk:'.$id;
    }

    public function rowCacheKey(mixed $id): string
    {
        $key = $this->cachePrefix();

        if (! $this->cacheUsesTags()) {
            $key .= ':rowv'.$this->getRowCacheVersion();
        }

        return $key.':pk:'.$id;
    }

    protected function rowCacheStore(mixed $id): CacheRepository
    {
        $store = $this->rawCacheStore();

        return $this->cacheUsesTags()
            ? $store->tags(array_merge($this->cacheTags(), [$this->rowTag($id)]))
            : $store;
    }

    public function flushRow(mixed $id): void
    {
        if ($this->cacheUsesTags()) {
            $this->rawCacheStore()->tags([$this->rowTag($id)])->flush();

            return;
        }

        $this->rawCacheStore()->forget($this->rowCacheKey($id));
    }

    /**
     * Cache a single-row find() under a stable per-id key. Null results are
     * not cached, so a later insert of that id is picked up without staleness.
     *
     * $hasTtlOverride distinguishes "no override" from an explicit override to
     * null (cacheFor(null) = cache forever).
     */
    public function rememberRowInCache(mixed $id, Closure $callback, ?int $ttlOverride = null, bool $hasTtlOverride = false): mixed
    {
        $store = $this->rowCacheStore($id);
        $key = $this->rowCacheKey($id);
        $miss = new \stdClass;

        $cached = $store->get($key, $miss);

        if ($cached !== $miss) {
            $this->recordCacheStat('hits');
            event(new CacheHit($this, $key));

            return $cached;
        }

        $this->recordCacheStat('misses');
        event(new CacheMissed($this, $key));

        $value = $callback();

        if ($value !== null) {
            $ttl = ($hasTtlOverride || $ttlOverride !== null)
                ? $ttlOverride
                : $this->cacheTtlForStorage();

            $ttl === null
                ? $store->forever($key, $value)
                : $store->put($key, $value, $ttl);
        }

        return $value;
    }

    /**
     * Resolve the models named by $flushRelated (relation names or classes).
     *
     * @return array<int, Model>
     */
    protected function relatedModelsToFlush(): array
    {
        $models = [];

        foreach ((array) $this->cacheOption('flushRelated', []) as $item) {
            try {
                if (is_string($item) && is_subclass_of($item, Model::class)) {
                    $models[] = new $item;
                } elseif (is_string($item) && method_exists($this, $item)) {
                    $relation = $this->{$item}();

                    if ($relation instanceof Relation) {
                        $models[] = $relation->getRelated();
                    }
                }
            } catch (\Throwable) {
                // Skip anything we can't resolve to a model.
            }
        }

        return array_filter($models, fn ($m) => method_exists($m, 'flushCache'));
    }

    // ---------------------------------------------------------------------
    // Cache warming
    // ---------------------------------------------------------------------

    /**
     * Queries to execute when warming this model's cache. Override to warm
     * the specific queries your application relies on.
     *
     * @return array<int, \Illuminate\Contracts\Database\Query\Builder|Builder>
     */
    public function cacheWarmupQueries(): array
    {
        return [static::query()];
    }
}

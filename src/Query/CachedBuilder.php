<?php

namespace Wddyousuf\AutoCache\Query;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Wddyousuf\AutoCache\Contracts\Cacheable;

/**
 * Eloquent builder whose job is to forward AutoCache's fluent controls to the
 * underlying {@see CachedQueryBuilder} (keeping the chain returning an Eloquent
 * builder so results stay hydrated as models) and to apply row-level caching
 * for canonical primary-key lookups via find().
 *
 * The generic parameter mirrors Eloquent\Builder's own. Without it, static
 * analysers widen every query on a Cacheable model back to a bare Model, so
 * `Post::query()->first()` resolves to Model instead of Post.
 *
 * @template TModelClass of Model
 *
 * @extends Builder<TModelClass>
 */
class CachedBuilder extends Builder
{
    /**
     * Cache canonical single-id lookups under a stable per-row key, so a
     * find() survives writes to other rows. Anything non-canonical (extra
     * constraints, custom columns, removed scopes, eager loads) falls back to
     * normal query caching.
     */
    public function find($id, $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return parent::find($id, $columns);
        }

        /** @var Model&Cacheable $model */
        $model = $this->getModel();
        $base = $this->getQuery();

        if (! $model->rowCacheEnabled() || (array) $columns !== ['*']) {
            return parent::find($id, $columns);
        }

        if ($model->cacheMode() === 'opt-in'
            && $base instanceof CachedQueryBuilder
            && ! $base->isCacheOptedIn()) {
            return parent::find($id, $columns);
        }

        if (! $this->isCanonicalFind()) {
            return parent::find($id, $columns);
        }

        // Fetch through a cache-disabled clone so the miss isn't ALSO stored by
        // the query-level cache (which would cache a null result and duplicate
        // hits). The row cache is the single home for canonical finds.
        $cachedBase = $base instanceof CachedQueryBuilder ? $base : null;

        return $model->rememberRowInCache(
            $id,
            fn () => (clone $this)->withoutCache()->whereKey($id)->first($columns),
            $cachedBase?->getCacheTtlOverride(),
            $cachedBase?->hasCacheTtlOverride() ?? false
        );
    }

    /**
     * A find is "canonical" when this builder carries only the model's default
     * scopes — i.e. its compiled SQL matches a fresh query with no extra
     * constraints and no eager loads.
     */
    protected function isCanonicalFind(): bool
    {
        return empty($this->getEagerLoads())
            && $this->toSql() === $this->getModel()->newQuery()->toSql();
    }

    /**
     * Bypass the cache for this query only (read and write flush still apply).
     */
    public function withoutCache(): static
    {
        $this->applyToBase(fn (CachedQueryBuilder $q) => $q->withoutCache());

        return $this;
    }

    /**
     * Override the TTL (seconds; null = forever) for this query only.
     */
    public function cacheFor(?int $ttl): static
    {
        $this->applyToBase(fn (CachedQueryBuilder $q) => $q->cacheFor($ttl));

        return $this;
    }

    /**
     * Use a fixed cache key for this query instead of the derived one.
     */
    public function cacheKey(?string $key): static
    {
        $this->applyToBase(fn (CachedQueryBuilder $q) => $q->cacheKey($key));

        return $this;
    }

    /**
     * Explicitly opt this query into caching (for the opt-in mode).
     */
    public function cache(): static
    {
        $this->applyToBase(fn (CachedQueryBuilder $q) => $q->cache());

        return $this;
    }

    /**
     * Apply a mutation to the base builder when it is cache-aware.
     */
    protected function applyToBase(callable $callback): void
    {
        $query = $this->getQuery();

        if ($query instanceof CachedQueryBuilder) {
            $callback($query);
        }
    }
}

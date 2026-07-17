# Changelog

All notable changes to `wddyousuf/eloquent-autocache` will be documented in
this file.

## 0.2.3 - 2026-07-17

### Added
- **Many-to-many (pivot) invalidation.** A many-to-many write
  (`$post->tags()->sync()`/`->attach()`/`->detach()`) is a bare pivot statement
  that never reaches a cacheable model's builder, so cached relation reads went
  stale until TTL — unsafe when the pivot gates authorization. Configure
  `autocache.pivot_invalidation.map` (`pivot table => [cacheable models]`) and
  AutoCache watches the query stream, flushing those models on any write to a
  listed pivot, wherever it originates. Opt-in: the listener is only registered
  when the map is non-empty, so there is zero query-stream overhead otherwise.

### Documentation
- README documents pivot invalidation, and notes that tag mode flushes the whole
  model per write (surgical single-row flush is version-counter mode only).

### Fixed
- Laravel 13 compatibility with the new secure-by-default
  `cache.serializable_classes => false`, which forbids unserializing any class
  (including `stdClass` and Eloquent models) out of a serializing cache store.
  Cached query rows are now stored as plain arrays and re-objectified on read,
  and canonical `find()` lookups cache the raw attribute array instead of the
  hydrated model. Previously, on a default Laravel 13 app any `->paginate()`
  threw in `getCountForPagination()` (#4), `find()` returned an unusable
  `__PHP_Incomplete_Class`, and cached `get()` results silently leaked a junk
  `__PHP_Incomplete_Class_Name` attribute. No configuration change is required.

### Documentation
- README now lists Laravel 13 under Requirements.

## 0.2.1 - 2026-07-15

### Fixed
- Restored the generic type parameter on `CachedBuilder` (`@template` +
  `@extends Builder<TModelClass>`) and annotated the trait's builder-returning
  methods as `CachedBuilder<static>`. Static analysers (PHPStan/Larastan) no
  longer widen queries on a `Cacheable` model back to a bare `Model`, so
  `Post::query()->first()` resolves to `Post` again. Docblock-only; no runtime
  change (#2).

## 0.2.0 - 2026-07-15

### Added
- Laravel 13 support: `illuminate/database`, `illuminate/support`, and
  `illuminate/cache` now allow `^13.0` (#3).

### Changed
- Dev dependencies widened for Laravel 13: `orchestra/testbench ^11.0` and
  `phpunit/phpunit ^12.0`.
- CI matrix now tests Laravel 13 on PHP 8.3 and 8.4.

## 0.1.0 - 2026-07-02

Initial release.

### Added
- `Cacheable` trait: transparent, self-invalidating query caching for Eloquent
  models via a single trait.
- Row-level caching: canonical `find($id)` lookups are cached under a stable
  per-row key and survive writes to other rows (version-counter stores).
- Opt-in caching mode (`mode => 'opt-in'`) with `->cache()` / `Model::cache()`,
  plus a per-model `$cacheMode` property overriding the global mode.
- Stale-while-revalidate (`swr`) via `Cache::flexible()` on Laravel 11+
  (skipped for models with a `max_rows` cap so the size guard always applies).
- `AutoCache::fake()` test double with `assertFlushed`, `assertNotFlushed`,
  `assertNothingFlushed`, `assertHit`, and `assertMissed`.
- Laravel Octane safety: process-static flush state resets each request/task/tick.
- Read caching at the base query builder (`runSelect`), so `get`,
  `first`, `find`, `pluck`, `value`, aggregates (`count`/`sum`/`avg`/`min`/`max`),
  `exists`, and pagination counts are all cached uniformly.
- Automatic invalidation on **every** write path, including bulk and joined
  query-builder writes, raw `insert`/`upsert`/`insertOrIgnore`/`insertUsing`/
  `insertOrIgnoreUsing`/`updateFrom`, `increment`/`decrement`, `truncate`, and
  event-suppressing "quiet" writes.
- Tag-based invalidation, auto-detected on taggable stores, with a version-
  counter fallback for every other store.
- Relationship-aware invalidation via a `$flushRelated` property.
- Transaction-aware flushing: immediate flush for read-after-write consistency
  plus an after-commit re-flush; rollbacks leave the cache untouched.
- Stampede protection using cache locks (`lock_for`).
- TTL jitter (`ttl_jitter`) to avoid synchronized expiry.
- Result-size guard (`max_rows`) and volatile-query skipping.
- Per-query controls: `withoutCache()`, `cacheFor()`, `cacheKey()`, plus the
  static `Model::withoutCache()` / `Model::cacheFor()` entry points.
- `CacheHit`, `CacheMissed`, and `CacheFlushed` events.
- Optional hit/miss statistics (`stats`).
- `AutoCache` facade and `autocache:flush`, `autocache:clear`,
  `autocache:warm` (with `--all`), and `autocache:stats` (with `--reset`)
  Artisan commands.

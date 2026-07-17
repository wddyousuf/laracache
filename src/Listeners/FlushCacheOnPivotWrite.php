<?php

namespace Wddyousuf\AutoCache\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Wddyousuf\AutoCache\Contracts\Cacheable;

/**
 * Flushes cacheable models whose reads depend on a many-to-many pivot, on any
 * write to that pivot — including the bare sync()/attach()/detach() statements
 * that never reach a cacheable model's own builder (and so bypass AutoCache's
 * normal write-time invalidation).
 *
 * Driven entirely by the `autocache.pivot_invalidation.map` config; the service
 * provider only registers this listener when that map is non-empty, so there is
 * no query-stream overhead for apps that don't use the feature.
 */
class FlushCacheOnPivotWrite
{
    private const WRITE_VERBS = ['insert', 'update', 'delete', 'replace'];

    public function handle(QueryExecuted $event): void
    {
        foreach ($this->modelsForWrite($event->sql) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $model = new $class;

            // The mapped class must carry the Cacheable trait (methods live on
            // the trait, not the base Model). Skip a misconfigured entry rather
            // than fatal on it.
            if (! method_exists($model, 'runFlush') || ! method_exists($model, 'flushCache')) {
                continue;
            }

            // Flush now for read-after-write consistency, and again after commit
            // when the pivot write is inside a transaction.
            /** @var Cacheable $model */
            $model->runFlush(fn () => $model->flushCache());
        }
    }

    /**
     * Deduplicated cacheable model classes to flush for this SQL statement.
     *
     * @return list<class-string>
     */
    private function modelsForWrite(string $sql): array
    {
        $verb = strtolower(strtok(ltrim($sql), " \n\t"));

        if (! in_array($verb, self::WRITE_VERBS, true)) {
            return [];
        }

        $map = (array) config('autocache.pivot_invalidation.map', []);

        $models = [];

        foreach ($map as $table => $classes) {
            if (is_string($table) && $table !== '' && str_contains($sql, $table)) {
                foreach ((array) $classes as $class) {
                    $models[] = $class;
                }
            }
        }

        return array_values(array_unique($models));
    }
}

<?php

namespace Wddyousuf\AutoCache\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Wddyousuf\AutoCache\Tests\Models\Post;
use Wddyousuf\AutoCache\Tests\Models\Tag;

/**
 * A many-to-many write (sync/attach/detach) is a bare pivot statement that
 * never reaches a cacheable model's builder, so a cached relation read would go
 * stale. The configured pivot map registers a query-stream listener that flushes
 * the mapped models on any write to the pivot.
 */
class PivotInvalidationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('autocache.pivot_invalidation.map', [
            'post_tag' => [Post::class, Tag::class],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tags', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('tag_id');
        });
    }

    public function test_attaching_a_tag_invalidates_the_cached_relation(): void
    {
        $post = Post::query()->first();
        $tag = Tag::create(['name' => 'x']);

        $ids = fn () => $post->tags()->pluck('tags.id')->all();

        // Warm, then confirm the relation read is genuinely cached.
        $this->assertSame([], $ids());
        $this->assertSame(0, $this->countSelects(fn () => $ids()));

        $post->tags()->attach($tag->id);

        // If the listener did not flush, the cached empty result would persist.
        $this->assertSame([$tag->id], $ids());
    }

    public function test_detaching_a_tag_invalidates_the_cached_relation(): void
    {
        $post = Post::query()->first();
        $tag = Tag::create(['name' => 'y']);
        $post->tags()->attach($tag->id);

        $ids = fn () => $post->tags()->pluck('tags.id')->all();

        // Warm on the attached state.
        $this->assertSame([$tag->id], $ids());
        $this->assertSame(0, $this->countSelects(fn () => $ids()));

        $post->tags()->detach($tag->id);

        $this->assertSame([], $ids());
    }
}

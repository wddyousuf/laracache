<?php

namespace Wddyousuf\AutoCache\Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Wddyousuf\AutoCache\Traits\Cacheable;

class Tag extends Model
{
    use Cacheable;

    protected $guarded = [];

    public $timestamps = false;

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag');
    }
}

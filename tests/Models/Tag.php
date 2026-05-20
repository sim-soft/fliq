<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * Tag Model Class.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 */
class Tag extends Model
{
    protected string $table = 'tag';

    protected array $fillable = ['name', 'slug'];

    /**
     * Get posts with this tag (M:N via post_tag pivot table).
     *
     * @return Relation
     */
    public function getPosts(): Relation
    {
        return $this->hasMany(Post::class, ['post_id' => 'id'])
            ->viaTable('post_tag', ['tag_id' => 'id']);
    }
}

<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * Category Model Class.
 *
 * @property int $id
 * @property int $parent_id
 * @property string $name
 * @property string $slug
 * @property int $status_code
 */
class Category extends Model
{
    protected string $table = 'category';

    protected array $fillable = ['parent_id', 'name', 'slug', 'status_code'];

    /**
     * Get posts in this category (1:N).
     *
     * @return Relation
     */
    public function getPosts(): Relation
    {
        return $this->hasMany(Post::class, ['category_id' => 'id']);
    }

    /**
     * Get child categories.
     *
     * @return Relation
     */
    public function getChildren(): Relation
    {
        return $this->hasMany(Category::class, ['parent_id' => 'id']);
    }
}

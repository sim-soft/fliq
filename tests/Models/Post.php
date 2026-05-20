<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * Post Model Class.
 *
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property int $view_count
 * @property int $status_code
 * @property string|null $published_at
 * @property string|null $deleted_at
 */
class Post extends Model
{
    protected string $table = 'post';

    protected array $fillable = ['user_id', 'category_id', 'title', 'slug', 'body', 'view_count', 'status_code', 'published_at'];

    /**
     * Get post author (belongs to user).
     *
     * @return Relation
     */
    public function getUser(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Eager-loadable author relation.
     *
     * @return Relation
     */
    public function user(): Relation
    {
        return $this->getUser();
    }

    /**
     * Get post comments (1:N).
     *
     * @return Relation
     */
    public function getComments(): Relation
    {
        return $this->hasMany(Comment::class, ['post_id' => 'id']);
    }

    /**
     * Eager-loadable comments relation.
     *
     * @return Relation
     */
    public function comments(): Relation
    {
        return $this->getComments();
    }

    /**
     * Get post category.
     *
     * @return Relation
     */
    public function getCategory(): Relation
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    /**
     * Eager-loadable category relation.
     *
     * @return Relation
     */
    public function category(): Relation
    {
        return $this->getCategory();
    }

    /**
     * Get post tags (M:N via post_tag pivot table).
     *
     * @return Relation
     */
    public function getTags(): Relation
    {
        return $this->hasMany(Tag::class, ['tag_id' => 'id'])
            ->viaTable('post_tag', ['post_id' => 'id']);
    }
}

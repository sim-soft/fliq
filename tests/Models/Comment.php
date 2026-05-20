<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * Comment Model Class.
 *
 * @property int $id
 * @property int $post_id
 * @property int $user_id
 * @property string $body
 * @property int $status_code
 */
class Comment extends Model
{
    protected string $table = 'comment';

    protected array $fillable = ['post_id', 'user_id', 'body', 'status_code'];

    /**
     * Get comment author.
     *
     * @return Relation
     */
    public function getUser(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * Get parent post.
     *
     * @return Relation
     */
    public function getPost(): Relation
    {
        return $this->hasOne(Post::class, ['id' => 'post_id']);
    }
}

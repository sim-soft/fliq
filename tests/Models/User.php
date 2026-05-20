<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * User Model Class.
 *
 * @property int $id
 * @property string $username
 * @property string $email
 * @property string $password
 * @property string $role
 * @property int $score
 * @property int $department_id
 * @property int $status_code
 * @property string|null $deleted_at
 * @property \Models\UserProfile|null $profile
 * @property \Models\Post[]|null $posts
 */
class User extends Model
{
    protected string $table = 'user';

    protected array $fillable = ['username', 'email', 'password', 'role', 'score', 'department_id', 'status_code'];

    protected array $guarded = ['id'];

    /**
     * Get user profile (1:1).
     *
     * @return Relation
     */
    public function getProfile(): Relation
    {
        return $this->hasOne(UserProfile::class, ['user_id' => 'id']);
    }

    /**
     * Eager-loadable profile relation.
     *
     * @return Relation
     */
    public function profile(): Relation
    {
        return $this->getProfile();
    }

    /**
     * Get user posts (1:N).
     *
     * @return Relation
     */
    public function getPosts(): Relation
    {
        return $this->hasMany(Post::class, ['user_id' => 'id']);
    }

    /**
     * Eager-loadable posts relation.
     *
     * @return Relation
     */
    public function posts(): Relation
    {
        return $this->getPosts();
    }

    /**
     * Get user orders (1:N).
     *
     * @return Relation
     */
    public function getOrders(): Relation
    {
        return $this->hasMany(Order::class, ['user_id' => 'id']);
    }

    /**
     * Eager-loadable orders relation.
     *
     * @return Relation
     */
    public function orders(): Relation
    {
        return $this->getOrders();
    }

    /**
     * Get user comments (1:N).
     *
     * @return Relation
     */
    public function getComments(): Relation
    {
        return $this->hasMany(Comment::class, ['user_id' => 'id']);
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
}

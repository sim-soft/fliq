<?php

namespace Models;

use Simsoft\DB\Model;

/**
 * PostTag Model Class (pivot table with composite primary key).
 *
 * @property int $post_id
 * @property int $tag_id
 */
class PostTag extends Model
{
    protected string $table = 'post_tag';

    protected string|array $primaryKey = ['post_id', 'tag_id'];

    protected array $fillable = ['post_id', 'tag_id'];
}

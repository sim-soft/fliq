<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Traits\SoftDeletes;
use Simsoft\DB\Traits\Timestamps;

/**
 * Task Model Class (uses SoftDeletes + Timestamps traits).
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property string $priority
 * @property string $status
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $deleted_at
 */
class Task extends Model
{
    use SoftDeletes;
    use Timestamps;

    protected string $table = 'task';

    protected array $fillable = ['user_id', 'title', 'description', 'priority', 'status'];
}

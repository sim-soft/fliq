<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * Department Model Class.
 *
 * @property int $id
 * @property string $name
 * @property float $budget
 * @property int $status_code
 */
class Department extends Model
{
    protected string $table = 'department';

    protected array $fillable = ['name', 'budget', 'status_code'];

    /**
     * Get users in this department.
     *
     * @return Relation
     */
    public function getUsers(): Relation
    {
        return $this->hasMany(User::class, ['department_id' => 'id']);
    }
}

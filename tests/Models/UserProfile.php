<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * UserProfile Model Class.
 *
 * @property int $id
 * @property int $user_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $phone
 * @property string|null $date_of_birth
 * @property string|null $bio
 */
class UserProfile extends Model
{
    protected string $table = 'user_profile';

    protected array $fillable = ['user_id', 'first_name', 'last_name', 'phone', 'date_of_birth', 'bio'];

    /**
     * Get the user this profile belongs to.
     *
     * @return Relation
     */
    public function getUser(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}

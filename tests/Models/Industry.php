<?php

namespace Models;

use Simsoft\DB\MySQL\Model;

/**
 * Industry Model Class.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property int $status_code
 */
class Industry extends Model
{
    protected string $table = 'industry';
}

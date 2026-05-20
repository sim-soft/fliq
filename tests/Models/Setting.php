<?php

namespace Models;

use Simsoft\DB\Model;

/**
 * Setting Model Class.
 *
 * @property int $id
 * @property string $group
 * @property string $key
 * @property string|null $value
 * @property string|null $metadata
 */
class Setting extends Model
{
    protected string $table = 'setting';

    protected array $fillable = ['group', 'key', 'value', 'metadata'];

    protected array $casts = [
        'metadata' => 'json',
    ];
}

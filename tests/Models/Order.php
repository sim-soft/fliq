<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * Order Model Class.
 *
 * @property int $id
 * @property int $user_id
 * @property float $total
 * @property float $discount
 * @property int $status_code
 * @property string|null $note
 * @property string|null $ordered_at
 */
class Order extends Model
{
    protected string $table = 'order';

    protected array $fillable = ['user_id', 'total', 'discount', 'status_code', 'note', 'ordered_at'];

    /**
     * Get order items (1:N).
     *
     * @return Relation
     */
    public function getItems(): Relation
    {
        return $this->hasMany(OrderItem::class, ['order_id' => 'id']);
    }

    /**
     * Get order owner.
     *
     * @return Relation
     */
    public function getUser(): Relation
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}

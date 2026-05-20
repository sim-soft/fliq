<?php

namespace Models;

use Simsoft\DB\Model;
use Simsoft\DB\Relation;

/**
 * OrderItem Model Class.
 *
 * @property int $id
 * @property int $order_id
 * @property string $product_name
 * @property int $quantity
 * @property float $unit_price
 */
class OrderItem extends Model
{
    protected string $table = 'order_item';

    protected array $fillable = ['order_id', 'product_name', 'quantity', 'unit_price'];

    /**
     * Get parent order.
     *
     * @return Relation
     */
    public function getOrder(): Relation
    {
        return $this->hasOne(Order::class, ['id' => 'order_id']);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $table = 'orders';
    protected $primaryKey = 'order_id';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $casts = [
        'order_id' => 'integer',
        'master_order_id' => 'integer',
        'buyer_userid' => 'integer',
        'start_time' => 'integer',
        'last_update_time' => 'integer',
        'items_count' => 'integer',
        'delivery_charge' => 'float',
        'order_total' => 'float',
        'discount' => 'float',
        'before_discount' => 'float',
        'delivered_time' => 'integer',
    ];
}

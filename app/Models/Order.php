<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\RestaurantTable;
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'customer_name',
        'phone',
        'address',
        'total',
        'status',
        'table_id',
        'table_token',

    ];
    protected $casts = [
        'total' => 'decimal:2',
        'status' => OrderStatus::class,
        'table_id' => 'integer',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
    public function table()
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantSubscription extends Model
{
    protected $fillable = [
        'restaurant_id',
        'plan',
        'billing_interval',
        'status',
        'amount',
        'currency',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}

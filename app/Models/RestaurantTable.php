<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
        'number',
        'qr_token',
        'status',
    ];

    protected static function booted()
    {
        static::creating(function ($table) {
            $table->qr_token = bin2hex(random_bytes(16));
        });
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'table_id');
    }
}

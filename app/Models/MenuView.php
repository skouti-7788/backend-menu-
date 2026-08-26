<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MenuView extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'meal_id',
        'language',
        'ip_address',
        'user_agent',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function meal()
    {
        return $this->belongsTo(Meal::class);
    }
}

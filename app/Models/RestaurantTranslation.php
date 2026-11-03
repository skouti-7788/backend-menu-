<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;
use Illuminate\\Database\\Eloquent\\Model;

class RestaurantTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'language',
        'name',
        'description',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantAppearance extends Model
{
    protected $fillable = [
        'restaurant_id',
        'logo',
        'background_image',
        'header_image',
        'primary_color',
        'secondary_color',
        'text_color',
        'background_color',
        'font_family',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
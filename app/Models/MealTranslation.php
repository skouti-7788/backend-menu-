<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'meal_id',
        'language',
        'name',
        'description',
        'is_manual',
        'source_hash',
    ];

    public function meal()
    {
        return $this->belongsTo(Meal::class);
    }
}

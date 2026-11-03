<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;
use Illuminate\\Database\\Eloquent\\Model;

class MenuCategoryTranslation extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_category_id',
        'language',
        'name',
        'description',
    ];

    public function category()
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }
}

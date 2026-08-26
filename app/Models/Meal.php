<?php

namespace App\Models;

use App\Enums\MealStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Meal extends Model
{
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'category_id',
        'name',
        'description',
        'price',
        'image',
        'status',
        'featured',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'status' => MealStatus::class,
        'featured' => 'bool',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category()
    {
        return $this->belongsTo(MenuCategory::class);
    }

    public function translations()
    {
        return $this->hasMany(MealTranslation::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function views()
    {
        return $this->hasMany(MenuView::class);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::make(get: fn () => $this->image ? Storage::disk('public')->url($this->image) : null);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\RestaurantTable;

class Restaurant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'logo',
        'cover_image',
        'description',
        'address',
        'phone',
        'email',
        'opening_hours',
        'social_links',
    ];

    protected $casts = [
        'opening_hours' => 'array',
        'social_links' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Restaurant $restaurant) {
            $restaurant->slug = $restaurant->slug ?: Str::slug($restaurant->name ?: 'restaurant').'-'.Str::lower(Str::random(6));
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function categories()
    {
        return $this->hasMany(MenuCategory::class);
    }

    public function meals()
    {
        return $this->hasMany(Meal::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function views()
    {
        return $this->hasMany(MenuView::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::make(get: fn () => $this->logo ? Storage::disk('public')->url($this->logo) : null);
    }

    protected function coverImageUrl(): Attribute
    {
        return Attribute::make(get: fn () => $this->cover_image ? Storage::disk('public')->url($this->cover_image) : null);
    }

    protected function menuUrl(): Attribute
    {
        return Attribute::make(get: fn () => rtrim(config('app.url'), '/').'/menu/'.$this->slug);
    }
    public function tables()
    {
        return $this->hasMany(RestaurantTable::class);
    }
}

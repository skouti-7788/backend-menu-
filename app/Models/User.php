<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Restaurant;
use App\Models\UserPermission;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'restaurant_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function restaurants()
    {
        return $this->hasMany(Restaurant::class);
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isRestaurantManager(): bool
    {
        return $this->role === 'restaurant_manager';
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    /**
     * Relationship: user permissions
     */
    public function permissions()
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * Check if the user has a permission string.
     */
    public function hasPermission(string $permission): bool
    {
        // Owner role implicitly has access for their restaurant
        if ($this->role === 'owner') {
            return true;
        }

        return (bool) $this->permissions()->where('permission', $permission)->exists();
    }
}

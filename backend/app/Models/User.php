<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'business_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function hasPermissionInBusiness(Business $business, string $permission): bool
    {
        return Role::query()
            ->where('business_id', $business->getKey())
            ->whereHas('permissions', fn ($query) => $query->where('key', $permission))
            ->whereHas('users', fn ($query) => $query
                ->whereKey($this->getKey())
                ->wherePivot('business_id', $business->getKey())
                ->wherePivot('status', 'active'))
            ->exists();
    }
}

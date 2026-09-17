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
        $membership = $this->businesses()
            ->whereKey($business->getKey())
            ->wherePivot('status', 'active')
            ->first();

        if (! $membership || ! $membership->pivot->role_id) {
            return false;
        }

        return Role::query()
            ->whereKey($membership->pivot->role_id)
            ->where('business_id', $business->getKey())
            ->whereHas(
                'permissions',
                fn ($query) => $query->where('key', $permission)
            )
            ->exists();
    }
}

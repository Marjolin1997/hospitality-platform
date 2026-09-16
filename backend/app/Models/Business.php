<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'legal_name', 'tax_number', 'currency', 'timezone', 'status'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'business_user')->withPivot(['role_id', 'status'])->withTimestamps();
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}

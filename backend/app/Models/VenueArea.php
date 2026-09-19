<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VenueArea extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = ['business_id', 'location_id', 'name', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function tables(): HasMany { return $this->hasMany(VenueTable::class)->orderBy('name'); }
}

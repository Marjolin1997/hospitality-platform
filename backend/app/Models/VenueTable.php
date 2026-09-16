<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenueTable extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = [
        'business_id', 'location_id', 'venue_area_id', 'name', 'capacity', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'capacity' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBusiness
{
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeForBusiness(Builder $query, Business|string $business): Builder
    {
        $businessId = $business instanceof Business ? $business->getKey() : $business;

        return $query->where($query->qualifyColumn('business_id'), $businessId);
    }
}

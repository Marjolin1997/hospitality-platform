<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class FiscalizationProfile extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_verified_at' => 'datetime',
        ];
    }
}

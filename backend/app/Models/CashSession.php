<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = [
        'business_id', 'location_id', 'cash_register_id', 'opened_by_user_id', 'closed_by_user_id',
        'base_currency', 'opening_cash', 'expected_cash', 'counted_cash', 'cash_difference',
        'status', 'opened_at', 'closed_at', 'closing_note',
    ];

    protected function casts(): array
    {
        return [
            'opening_cash' => 'decimal:4', 'expected_cash' => 'decimal:4',
            'counted_cash' => 'decimal:4', 'cash_difference' => 'decimal:4',
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}

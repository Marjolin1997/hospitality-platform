<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = [
        'business_id', 'cash_session_id', 'created_by_user_id', 'type', 'amount', 'currency',
        'amount_base', 'exchange_rate', 'reason', 'reference_type', 'reference_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4', 'amount_base' => 'decimal:4',
            'exchange_rate' => 'decimal:10', 'occurred_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }
}

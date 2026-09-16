<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = [
        'business_id', 'payment_id', 'cash_session_id', 'refunded_by_user_id', 'amount',
        'amount_base', 'currency', 'base_currency', 'exchange_rate', 'reason',
        'idempotency_key', 'status', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4', 'amount_base' => 'decimal:4',
            'exchange_rate' => 'decimal:10', 'refunded_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function cashSession(): BelongsTo { return $this->belongsTo(CashSession::class); }
}

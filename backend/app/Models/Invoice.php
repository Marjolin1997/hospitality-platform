<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Invoice extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'exchange_rate' => 'decimal:10',
            'subtotal_foreign' => 'decimal:4',
            'discount_total_foreign' => 'decimal:4',
            'tax_total_foreign' => 'decimal:4',
            'grand_total_foreign' => 'decimal:4',
            'exchange_rate_effective_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(InvoiceCreditNote::class);
    }
}

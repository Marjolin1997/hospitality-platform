<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = [
        'business_id', 'order_id', 'product_id', 'product_name_snapshot', 'sku_snapshot',
        'quantity', 'unit_price', 'original_unit_price', 'tax_rate', 'line_subtotal', 'line_tax', 'line_total',
        'preparation_station', 'preparation_status', 'note', 'sent_at', 'preparing_at',
        'prepared_at', 'served_at', 'void_reason', 'voided_by_user_id', 'voided_at',
        'price_override_reason', 'price_overridden_by_user_id', 'price_overridden_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'original_unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:4', 'line_subtotal' => 'decimal:4', 'line_tax' => 'decimal:4', 'line_total' => 'decimal:4',
            'sent_at' => 'datetime', 'preparing_at' => 'datetime', 'prepared_at' => 'datetime',
            'served_at' => 'datetime', 'voided_at' => 'datetime', 'price_overridden_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function voidedBy(): BelongsTo { return $this->belongsTo(User::class, 'voided_by_user_id'); }
    public function priceOverriddenBy(): BelongsTo { return $this->belongsTo(User::class, 'price_overridden_by_user_id'); }
}

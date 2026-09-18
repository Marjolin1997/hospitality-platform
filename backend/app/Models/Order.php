<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use BelongsToBusiness, HasUlids;

    protected $fillable = [
        'business_id', 'location_id', 'venue_table_id', 'previous_venue_table_id', 'opened_by_user_id',
        'cancelled_by_user_id', 'discount_applied_by_user_id', 'table_moved_by_user_id',
        'number', 'type', 'status', 'cancel_reason', 'table_move_reason',
        'currency', 'subtotal', 'discount_total', 'discount_reason', 'tax_total', 'grand_total',
        'opened_at', 'closed_at', 'cancelled_at', 'discount_applied_at', 'table_moved_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'discount_applied_at' => 'datetime',
            'table_moved_at' => 'datetime',
        ];
    }

    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function invoice(): HasOne { return $this->hasOne(Invoice::class); }
    public function cancelledBy(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by_user_id'); }
    public function discountAppliedBy(): BelongsTo { return $this->belongsTo(User::class, 'discount_applied_by_user_id'); }
    public function tableMovedBy(): BelongsTo { return $this->belongsTo(User::class, 'table_moved_by_user_id'); }
    public function venueTable(): BelongsTo { return $this->belongsTo(VenueTable::class); }
    public function previousVenueTable(): BelongsTo { return $this->belongsTo(VenueTable::class, 'previous_venue_table_id'); }
}

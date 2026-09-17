<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use BelongsToBusiness, HasUlids;
    protected $fillable=['business_id','order_id','cash_session_id','collected_by_user_id','method','status','amount','amount_base','currency','base_currency','exchange_rate','tendered_amount','change_amount','exchange_rate_snapshot','idempotency_key','external_reference','paid_at'];
    protected function casts():array{return ['amount'=>'decimal:4','amount_base'=>'decimal:4','exchange_rate'=>'decimal:10','tendered_amount'=>'decimal:4','change_amount'=>'decimal:4','exchange_rate_snapshot'=>'array','paid_at'=>'datetime'];}
    public function order():BelongsTo{return $this->belongsTo(Order::class);}
    public function cashSession():BelongsTo{return $this->belongsTo(CashSession::class);}
    public function refunds():HasMany{return $this->hasMany(PaymentRefund::class)->orderByDesc('refunded_at');}
}

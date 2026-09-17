<?php

namespace App\Services\Sales;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\VenueTable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrderTransferService
{
    private const EDITABLE = ['open', 'payment_due'];
    public function __construct(private readonly OrderTotalsCalculator $totals) {}

    public function split(Business $business, User $user, Order $source, array $payload): array
    {
        return DB::transaction(function () use ($business,$user,$source,$payload): array {
            if ($replay=$this->replay($business,$payload['idempotency_key'],'split',(string)$source->getKey(),null,$payload)) return $replay;
            $source=$this->lockOrder($business,(string)$source->getKey()); $this->assertTransferable($source); $this->assertNoDiscount($source);
            $items=OrderItem::query()->forBusiness($business)->where('order_id',$source->getKey())->whereIn('id',$payload['item_ids'])->orderBy('id')->lockForUpdate()->get();
            if($items->count()!==count($payload['item_ids'])) throw ValidationException::withMessages(['item_ids'=>'One or more selected items do not belong to this order.']);
            if($items->contains(fn(OrderItem $i)=>$i->preparation_status==='voided')) throw ValidationException::withMessages(['item_ids'=>'Voided items cannot be split.']);
            $allActive=OrderItem::query()->forBusiness($business)->where('order_id',$source->getKey())->where('preparation_status','!=','voided')->orderBy('id')->lockForUpdate()->get();
            if($items->count()>=$allActive->count()) throw ValidationException::withMessages(['item_ids'=>'A split must leave at least one active item on the source order.']);
            $tableId=$payload['venue_table_id']??null; if($tableId!==null){if($source->type!=='table') throw ValidationException::withMessages(['venue_table_id'=>'Only table orders can be split to another table.']);$this->lockAvailableTable($business,$source,$tableId);}
            $before=BigDecimal::of((string)$source->grand_total);
            $destination=Order::create(['business_id'=>$business->getKey(),'location_id'=>$source->location_id,'venue_table_id'=>$tableId??$source->venue_table_id,'opened_by_user_id'=>$user->getKey(),'number'=>$this->nextNumber($business),'type'=>$source->type,'status'=>'open','currency'=>$source->currency,'subtotal'=>'0.0000','discount_total'=>'0.0000','tax_total'=>'0.0000','grand_total'=>'0.0000','opened_at'=>now()]);
            OrderItem::whereIn('id',$items->pluck('id'))->update(['order_id'=>$destination->getKey(),'updated_at'=>now()]);
            $source=$this->recalculate($source);$destination=$this->recalculate($destination);$this->assertConserved($before,$source,$destination);
            $this->audit($business,$user,'split',$payload['idempotency_key'],$source,$destination,$items->pluck('id')->all(),$payload['reason'],$before,$payload);
            return ['source'=>$source,'destination'=>$destination];
        },attempts:3);
    }

    public function merge(Business $business,User $user,Order $destination,array $payload):Order
    {
        return DB::transaction(function()use($business,$user,$destination,$payload):Order{
            $sourceId=(string)$payload['source_order_id'];$destId=(string)$destination->getKey();
            if($replay=$this->replay($business,$payload['idempotency_key'],'merge',$sourceId,$destId,$payload)) return $replay['destination'];
            if($destId===$sourceId) throw ValidationException::withMessages(['source_order_id'=>'An order cannot be merged into itself.']);
            $ids=collect([$destId,$sourceId])->sort()->values();$locked=Order::query()->forBusiness($business)->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');if($locked->count()!==2) abort(404);
            $destination=$locked->get($destId);$source=$locked->get($sourceId);$this->assertTransferable($source);$this->assertTransferable($destination);$this->assertNoDiscount($source);$this->assertNoDiscount($destination);
            if((string)$source->location_id!==(string)$destination->location_id) throw ValidationException::withMessages(['source_order_id'=>'Orders from different locations cannot be merged.']);
            if((string)$source->currency!==(string)$destination->currency) throw ValidationException::withMessages(['source_order_id'=>'Orders with different currencies cannot be merged.']);
            $orderIds=collect([$sourceId,$destId])->sort()->values();$lockedItems=OrderItem::query()->forBusiness($business)->whereIn('order_id',$orderIds)->orderBy('order_id')->orderBy('id')->lockForUpdate()->get();$sourceItems=$lockedItems->where('order_id',$sourceId)->where('preparation_status','!=','voided');if($sourceItems->isEmpty()) throw ValidationException::withMessages(['source_order_id'=>'Source order has no active items to merge.']);
            $before=BigDecimal::of((string)$source->grand_total)->plus((string)$destination->grand_total);OrderItem::whereIn('id',$sourceItems->pluck('id'))->update(['order_id'=>$destination->getKey(),'updated_at'=>now()]);$destination=$this->recalculate($destination);
            $source->forceFill(['status'=>'closed','closed_at'=>now(),'subtotal'=>'0.0000','tax_total'=>'0.0000','grand_total'=>'0.0000'])->save();$source=$source->fresh(['items','payments.refunds']);$this->assertConserved($before,$source,$destination);
            $this->audit($business,$user,'merge',$payload['idempotency_key'],$source,$destination,$sourceItems->pluck('id')->all(),$payload['reason'],$before,$payload);return $destination;
        },attempts:3);
    }

    private function replay(Business $business,string $key,string $operation,string $sourceId,?string $destinationId,array $payload):?array
    {
        $audit=DB::table('order_transfer_audits')->where('business_id',$business->getKey())->where('idempotency_key',$key)->lockForUpdate()->first();if(!$audit)return null;
        $same=$audit->operation===$operation&&(string)$audit->source_order_id===$sourceId&&($destinationId===null|| (string)$audit->destination_order_id===$destinationId)&&$this->samePayload($audit,$payload);
        if(!$same)throw ValidationException::withMessages(['idempotency_key'=>'This idempotency key was already used for a different order transfer request.']);
        $source=Order::query()->forBusiness($business)->whereKey($audit->source_order_id)->firstOrFail();$destination=Order::query()->forBusiness($business)->whereKey($audit->destination_order_id)->firstOrFail();return['source'=>$source->load('items'),'destination'=>$destination->load('items')];
    }
    private function samePayload(object $audit,array $payload):bool{$stored=json_decode($audit->request_snapshot??'{}',true);$current=['item_ids'=>array_values($payload['item_ids']??[]),'venue_table_id'=>$payload['venue_table_id']??null,'source_order_id'=>$payload['source_order_id']??null,'reason'=>trim($payload['reason'])];return $stored===$current;}
    private function lockOrder(Business $b,string $id):Order{return Order::query()->forBusiness($b)->whereKey($id)->lockForUpdate()->firstOrFail();}
    private function assertTransferable(Order $o):void{if(!in_array($o->status,self::EDITABLE,true))throw ValidationException::withMessages(['order'=>'This order cannot be split or merged in its current state.']);if($o->payments()->where('status','completed')->exists())throw ValidationException::withMessages(['order'=>'Split and merge are blocked after payment has started.']);}
    private function assertNoDiscount(Order $o):void{if(BigDecimal::of((string)$o->discount_total)->isPositive())throw ValidationException::withMessages(['order'=>'Remove the order-level discount before split or merge so financial allocation remains explicit.']);}
    private function lockAvailableTable(Business $b,Order $s,string $id):void{VenueTable::query()->forBusiness($b)->whereKey($id)->where('location_id',$s->location_id)->where('is_active',true)->lockForUpdate()->firstOrFail();if(Order::query()->forBusiness($b)->where('location_id',$s->location_id)->where('venue_table_id',$id)->whereIn('status',self::EDITABLE)->whereKeyNot($s->getKey())->lockForUpdate()->exists())throw ValidationException::withMessages(['venue_table_id'=>'Destination table already has an active order; merge into that order instead.']);}
    private function recalculate(Order $o):Order{$items=OrderItem::query()->forBusiness($o->business_id)->where('order_id',$o->getKey())->where('preparation_status','!=','voided')->get();if($items->isEmpty()){$o->forceFill(['subtotal'=>'0.0000','tax_total'=>'0.0000','grand_total'=>'0.0000'])->save();return$o->fresh(['items','payments.refunds']);}$c=$this->totals->calculate($items->map(fn(OrderItem $i)=>['quantity'=>(string)$i->quantity,'unit_price'=>(string)$i->unit_price,'tax_rate'=>(string)$i->tax_rate])->all());$o->forceFill(['subtotal'=>$c['subtotal'],'tax_total'=>$c['tax_total'],'grand_total'=>$c['grand_total']])->save();return$o->fresh(['items','payments.refunds']);}
    private function assertConserved(BigDecimal $before,Order $s,Order $d):void{if(!$before->isEqualTo(BigDecimal::of((string)$s->grand_total)->plus((string)$d->grand_total)))throw ValidationException::withMessages(['order'=>'Financial conservation check failed; no split or merge was committed.']);}
    private function audit(Business $b,User $u,string $op,string $key,Order $s,Order $d,array $ids,string $reason,BigDecimal $before,array $payload):void{DB::table('order_transfer_audits')->insert(['id'=>(string)Str::ulid(),'business_id'=>$b->getKey(),'location_id'=>$s->location_id,'source_order_id'=>$s->getKey(),'destination_order_id'=>$d->getKey(),'performed_by_user_id'=>$u->getKey(),'operation'=>$op,'idempotency_key'=>$key,'reason'=>trim($reason),'total_before'=>(string)$before->toScale(4,RoundingMode::HALF_UP),'source_total_after'=>(string)BigDecimal::of((string)$s->grand_total)->toScale(4,RoundingMode::HALF_UP),'destination_total_after'=>(string)BigDecimal::of((string)$d->grand_total)->toScale(4,RoundingMode::HALF_UP),'item_ids'=>json_encode(array_values($ids),JSON_THROW_ON_ERROR),'request_snapshot'=>json_encode(['item_ids'=>array_values($payload['item_ids']??[]),'venue_table_id'=>$payload['venue_table_id']??null,'source_order_id'=>$payload['source_order_id']??null,'reason'=>trim($reason)],JSON_THROW_ON_ERROR),'performed_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function nextNumber(Business $b):string{$n=CarbonImmutable::now($b->timezone);$date=$n->toDateString();DB::statement('INSERT INTO business_order_counters (business_id,business_date,last_number,created_at,updated_at) VALUES (?,?,LAST_INSERT_ID(1),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_number=LAST_INSERT_ID(last_number+1),updated_at=UTC_TIMESTAMP()',[$b->getKey(),$date]);$seq=(int)DB::selectOne('SELECT LAST_INSERT_ID() AS sequence')->sequence;return sprintf('%s-%04d',$n->format('Ymd'),$seq);}
}

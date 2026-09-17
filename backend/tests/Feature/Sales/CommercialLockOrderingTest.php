<?php

use Illuminate\Support\Facades\File;

function cloSource(string $relative): string
{
    return File::get(app_path($relative));
}

function cloPosition(string $source, string $needle): int
{
    $position = strpos($source, $needle);
    expect($position)->not->toBeFalse("Expected source fragment was not found: {$needle}");

    return $position;
}

test('payment acquires the order lock before idempotency and dependent row locks', function (): void {
    $source = cloSource('Services/Payments/CollectPayment.php');

    $orderLock = cloPosition($source, '->whereKey($order->getKey())\n                ->lockForUpdate()');
    $idempotency = cloPosition($source, "->where('idempotency_key', \$payload['idempotency_key'])");
    $cashSession = cloPosition($source, 'CashSession::query()->forBusiness($business)');
    $paymentCreate = cloPosition($source, '$payment = Payment::query()->create([');

    expect($orderLock)->toBeLessThan($idempotency)
        ->and($idempotency)->toBeLessThan($cashSession)
        ->and($cashSession)->toBeLessThan($paymentCreate);
});

test('split acquires source order before replay and item locks', function (): void {
    $source = cloSource('Services/Sales/OrderTransferService.php');

    $splitStart = cloPosition($source, 'public function split(');
    $mergeStart = cloPosition($source, 'public function merge(');
    $split = substr($source, $splitStart, $mergeStart - $splitStart);

    $orderLock = cloPosition($split, '$s=$this->lockOrder(');
    $replay = cloPosition($split, '$this->replay(');
    $itemLock = cloPosition($split, "->whereIn('id',\$p['item_ids'])->orderBy('id')->lockForUpdate()");

    expect($orderLock)->toBeLessThan($replay)
        ->and($replay)->toBeLessThan($itemLock);
});

test('merge uses deterministic sorted order locks before replay and item locks', function (): void {
    $source = cloSource('Services/Sales/OrderTransferService.php');
    $mergeStart = cloPosition($source, 'public function merge(');
    $replayMethod = cloPosition($source, 'private function replay(');
    $merge = substr($source, $mergeStart, $replayMethod - $mergeStart);

    $sort = cloPosition($merge, 'collect([$did,$sid])->sort()->values()');
    $orderLock = cloPosition($merge, "->whereIn('id',\$ids)->orderBy('id')->lockForUpdate()");
    $replay = cloPosition($merge, '$this->replay(');
    $itemLock = cloPosition($merge, "->whereIn('order_id',\$orderIds)->orderBy('order_id')->orderBy('id')->lockForUpdate()");

    expect($sort)->toBeLessThan($orderLock)
        ->and($orderLock)->toBeLessThan($replay)
        ->and($replay)->toBeLessThan($itemLock);
});

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BarQueueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = OrderItem::query()->forBusiness(app(Business::class))
            ->whereIn('preparation_status', ['sent', 'preparing', 'ready'])
            ->when($request->string('station')->isNotEmpty(), fn ($q) => $q->where('preparation_station', $request->string('station')->toString()))
            ->with('order')->orderByRaw("FIELD(preparation_status, 'preparing', 'sent', 'ready')")->orderBy('sent_at')->get();
        return response()->json(['data' => $items]);
    }

    public function transition(Request $request, string $item): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['preparing', 'ready', 'served'])]]);
        $record = DB::transaction(function () use ($item, $validated) {
            $record = OrderItem::query()->forBusiness(app(Business::class))->whereKey($item)->lockForUpdate()->firstOrFail();
            $allowed = ['sent' => 'preparing', 'preparing' => 'ready', 'ready' => 'served'];
            if (($allowed[$record->preparation_status] ?? null) !== $validated['status']) {
                throw ValidationException::withMessages(['status' => 'Invalid preparation status transition.']);
            }
            $updates = ['preparation_status' => $validated['status']];
            if ($validated['status'] === 'ready') $updates['prepared_at'] = now();
            $record->forceFill($updates)->save();
            return $record->fresh('order');
        });
        return response()->json(['data' => $record]);
    }
}

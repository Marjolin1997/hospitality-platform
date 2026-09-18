<?php

namespace App\Services\Tenancy;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageBusinessLocation
{
    public function create(Business $business, array $data, int $performedByUserId): object
    {
        return DB::transaction(function () use ($business, $data, $performedByUserId): object {
            $this->lockBusiness($business);
            $this->assertUniqueIdentity($business, $data['name'], $data['code']);

            $id = (string) Str::ulid();
            DB::table('locations')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'name' => trim($data['name']),
                'code' => strtoupper(trim($data['code'])),
                'type' => $data['type'],
                'address' => $data['address'] ?? null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $location = $this->location($business, $id);
            $this->invalidateFiscalPreflight($business);
            $this->audit($business, $location, $performedByUserId, 'created', null, $this->state($location));

            return $this->normalize($location);
        }, 3);
    }

    public function update(Business $business, string $locationId, array $data, int $performedByUserId): object
    {
        return DB::transaction(function () use ($business, $locationId, $data, $performedByUserId): object {
            $this->lockBusiness($business);

            $location = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $locationId)
                ->lockForUpdate()
                ->first();

            abort_unless($location, 404);

            $this->assertUniqueIdentity($business, $data['name'], $data['code'], $locationId);
            $before = $this->state($location);

            DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $locationId)
                ->update([
                    'name' => trim($data['name']),
                    'code' => strtoupper(trim($data['code'])),
                    'type' => $data['type'],
                    'address' => $data['address'] ?? null,
                    'updated_at' => now(),
                ]);

            $updated = $this->location($business, $locationId);
            $after = $this->state($updated);

            if ($before !== $after) {
                $this->audit($business, $updated, $performedByUserId, 'updated', $before, $after);
            }

            return $this->normalize($updated);
        }, 3);
    }

    public function setStatus(Business $business, string $locationId, bool $isActive, int $performedByUserId): object
    {
        return DB::transaction(function () use ($business, $locationId, $isActive, $performedByUserId): object {
            $this->lockBusiness($business);

            $location = DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $locationId)
                ->lockForUpdate()
                ->first();

            abort_unless($location, 404);

            if ((bool) $location->is_active === $isActive) {
                return $this->normalize($location);
            }

            if (! $isActive) {
                $activeLocationIds = DB::table('locations')
                    ->where('business_id', $business->getKey())
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($activeLocationIds->count() <= 1) {
                    throw ValidationException::withMessages([
                        'location' => 'At least one active location must remain in the business.',
                    ]);
                }

                $hasOpenOrders = DB::table('orders')
                    ->where('business_id', $business->getKey())
                    ->where('location_id', $locationId)
                    ->whereIn('status', ['open', 'payment_due'])
                    ->exists();

                if ($hasOpenOrders) {
                    throw ValidationException::withMessages([
                        'location' => 'Close or move all open orders before disabling this location.',
                    ]);
                }

                $hasOpenCashSessions = DB::table('cash_sessions')
                    ->where('business_id', $business->getKey())
                    ->where('location_id', $locationId)
                    ->where('status', 'open')
                    ->exists();

                if ($hasOpenCashSessions) {
                    throw ValidationException::withMessages([
                        'location' => 'Close all cash register sessions before disabling this location.',
                    ]);
                }
            }

            $before = $this->state($location);

            DB::table('locations')
                ->where('business_id', $business->getKey())
                ->where('id', $locationId)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            $updated = $this->location($business, $locationId);
            $this->invalidateFiscalPreflight($business);
            $this->audit($business, $updated, $performedByUserId, 'status_changed', $before, $this->state($updated));

            return $this->normalize($updated);
        }, 3);
    }

    private function invalidateFiscalPreflight(Business $business): void
    {
        DB::table('fiscalization_profiles')
            ->where('business_id', $business->getKey())
            ->update([
                'preflight_checked_at' => null,
                'preflight_status' => null,
                'updated_at' => now(),
            ]);
    }

    private function lockBusiness(Business $business): void
    {
        DB::table('businesses')
            ->where('id', $business->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function assertUniqueIdentity(Business $business, string $name, string $code, ?string $exceptId = null): void
    {
        $nameQuery = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->whereRaw('LOWER(name) = ?', [Str::lower(trim($name))]);

        $codeQuery = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('code', strtoupper(trim($code)));

        if ($exceptId !== null) {
            $nameQuery->where('id', '!=', $exceptId);
            $codeQuery->where('id', '!=', $exceptId);
        }

        if ($nameQuery->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A location with this name already exists in this business.',
            ]);
        }

        if ($codeQuery->exists()) {
            throw ValidationException::withMessages([
                'code' => 'A location with this code already exists in this business.',
            ]);
        }
    }

    private function location(Business $business, string $locationId): object
    {
        return DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('id', $locationId)
            ->firstOrFail();
    }

    private function normalize(object $location): object
    {
        $location->is_active = (bool) $location->is_active;

        return $location;
    }

    private function state(object $location): array
    {
        return [
            'name' => $location->name,
            'code' => $location->code,
            'type' => $location->type,
            'address' => $location->address,
            'is_active' => (bool) $location->is_active,
        ];
    }

    private function audit(
        Business $business,
        object $location,
        int $performedByUserId,
        string $action,
        ?array $previousState,
        ?array $newState,
    ): void {
        DB::table('business_location_audits')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'location_id' => $location->id,
            'performed_by_user_id' => $performedByUserId,
            'action' => $action,
            'previous_state' => $previousState === null ? null : json_encode($previousState, JSON_THROW_ON_ERROR),
            'new_state' => $newState === null ? null : json_encode($newState, JSON_THROW_ON_ERROR),
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

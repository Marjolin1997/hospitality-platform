<?php

namespace App\Services\Operations;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManagePreparationStation
{
    public function save(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $this->lockBusiness($business);

            $station = null;
            if (! empty($data['id'])) {
                $station = DB::table('preparation_stations')
                    ->where('business_id', $business->getKey())
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();

                abort_unless($station, 404);

                if ($station->code !== $data['code']) {
                    throw ValidationException::withMessages([
                        'code' => 'Station code is immutable because historical order items store it as a routing snapshot.',
                    ]);
                }
            }

            $nameDuplicate = DB::table('preparation_stations')
                ->where('business_id', $business->getKey())
                ->whereRaw('LOWER(name) = ?', [Str::lower($data['name'])]);

            $codeDuplicate = DB::table('preparation_stations')
                ->where('business_id', $business->getKey())
                ->whereRaw('LOWER(code) = ?', [Str::lower($data['code'])]);

            if ($station) {
                $nameDuplicate->where('id', '!=', $station->id);
                $codeDuplicate->where('id', '!=', $station->id);
            }

            if ($nameDuplicate->exists()) {
                throw ValidationException::withMessages([
                    'name' => 'A preparation station with this name already exists.',
                ]);
            }

            if ($codeDuplicate->exists()) {
                throw ValidationException::withMessages([
                    'code' => 'A preparation station with this code already exists.',
                ]);
            }

            $payload = [
                'name' => trim($data['name']),
                'code' => $data['code'],
                'sort_order' => (int) $data['sort_order'],
                'updated_at' => now(),
            ];

            if ($station) {
                $before = $this->state($station);

                DB::table('preparation_stations')
                    ->where('business_id', $business->getKey())
                    ->where('id', $station->id)
                    ->update($payload);

                $updated = $this->station($business, (string) $station->id);
                $after = $this->state($updated);

                if ($before !== $after) {
                    $this->audit($business, $actorUserId, (string) $station->id, 'updated', $before, $after);
                }

                return $this->normalize($updated);
            }

            $id = (string) Str::ulid();

            DB::table('preparation_stations')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                ...$payload,
                'is_active' => true,
                'created_at' => now(),
            ]);

            $created = $this->station($business, $id);
            $this->audit($business, $actorUserId, $id, 'created', null, $this->state($created));

            return $this->normalize($created);
        }, 3);
    }

    public function setStatus(Business $business, string $stationId, bool $isActive, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $stationId, $isActive, $actorUserId): object {
            $this->lockBusiness($business);

            $station = DB::table('preparation_stations')
                ->where('business_id', $business->getKey())
                ->where('id', $stationId)
                ->lockForUpdate()
                ->first();

            abort_unless($station, 404);

            if ((bool) $station->is_active === $isActive) {
                return $this->normalize($station);
            }

            if (! $isActive) {
                $activeProducts = DB::table('products')
                    ->where('business_id', $business->getKey())
                    ->where('preparation_station', $station->code)
                    ->where('is_active', true)
                    ->count();

                if ($activeProducts > 0) {
                    throw ValidationException::withMessages([
                        'station' => "Move or disable {$activeProducts} active product(s) before disabling this station.",
                    ]);
                }
            }

            $before = $this->state($station);

            DB::table('preparation_stations')
                ->where('business_id', $business->getKey())
                ->where('id', $stationId)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            $updated = $this->station($business, $stationId);
            $this->audit($business, $actorUserId, $stationId, 'status_changed', $before, $this->state($updated));

            return $this->normalize($updated);
        }, 3);
    }

    private function lockBusiness(Business $business): void
    {
        DB::table('businesses')
            ->where('id', $business->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function station(Business $business, string $stationId): object
    {
        return DB::table('preparation_stations')
            ->where('business_id', $business->getKey())
            ->where('id', $stationId)
            ->firstOrFail();
    }

    private function normalize(object $station): object
    {
        $station->is_active = (bool) $station->is_active;
        $station->sort_order = (int) $station->sort_order;

        return $station;
    }

    private function state(object $station): array
    {
        return [
            'name' => $station->name,
            'code' => $station->code,
            'sort_order' => (int) $station->sort_order,
            'is_active' => (bool) $station->is_active,
        ];
    }

    private function audit(
        Business $business,
        int $actorUserId,
        string $stationId,
        string $action,
        ?array $previous,
        array $next,
    ): void {
        DB::table('business_configuration_audits')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'location_id' => null,
            'performed_by_user_id' => $actorUserId,
            'entity_type' => 'preparation_station',
            'entity_id' => $stationId,
            'action' => $action,
            'previous_state' => $previous === null ? null : json_encode($previous, JSON_THROW_ON_ERROR),
            'new_state' => json_encode($next, JSON_THROW_ON_ERROR),
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

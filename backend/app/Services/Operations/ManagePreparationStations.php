<?php

namespace App\Services\Operations;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManagePreparationStations
{
    public function save(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            DB::table('businesses')->where('id', $business->getKey())->lockForUpdate()->firstOrFail();

            $station = null;
            if (! empty($data['id'])) {
                $station = DB::table('preparation_stations')
                    ->where('business_id', $business->getKey())
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();
                abort_unless($station, 404);
            }

            $name = trim($data['name']);
            $code = Str::lower(trim($data['code']));

            $duplicateName = DB::table('preparation_stations')
                ->where('business_id', $business->getKey())
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);
            $duplicateCode = DB::table('preparation_stations')
                ->where('business_id', $business->getKey())
                ->whereRaw('LOWER(code) = ?', [$code]);

            if ($station) {
                $duplicateName->where('id', '!=', $station->id);
                $duplicateCode->where('id', '!=', $station->id);
            }

            if ($duplicateName->exists()) {
                throw ValidationException::withMessages(['name' => 'A preparation station with this name already exists.']);
            }
            if ($duplicateCode->exists()) {
                throw ValidationException::withMessages(['code' => 'A preparation station with this code already exists.']);
            }

            $before = $station ? $this->state($station) : null;

            if ($station) {
                DB::table('preparation_stations')
                    ->where('business_id', $business->getKey())
                    ->where('id', $station->id)
                    ->update([
                        'name' => $name,
                        'code' => $code,
                        'sort_order' => (int) $data['sort_order'],
                        'updated_at' => now(),
                    ]);

                if ($station->code !== $code) {
                    DB::table('products')
                        ->where('business_id', $business->getKey())
                        ->where('preparation_station', $station->code)
                        ->update([
                            'preparation_station' => $code,
                            'updated_at' => now(),
                        ]);
                }

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
                'name' => $name,
                'code' => $code,
                'sort_order' => (int) $data['sort_order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created = $this->station($business, $id);
            $this->audit($business, $actorUserId, $id, 'created', null, $this->state($created));

            return $this->normalize($created);
        }, 3);
    }

    public function setStatus(Business $business, string $stationId, bool $isActive, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $stationId, $isActive, $actorUserId): object {
            DB::table('businesses')->where('id', $business->getKey())->lockForUpdate()->firstOrFail();

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
                        'station' => 'Reassign or disable active products before disabling this preparation station.',
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

    private function station(Business $business, string $id): object
    {
        return DB::table('preparation_stations')
            ->where('business_id', $business->getKey())
            ->where('id', $id)
            ->firstOrFail();
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

    private function normalize(object $station): object
    {
        $station->sort_order = (int) $station->sort_order;
        $station->is_active = (bool) $station->is_active;

        return $station;
    }

    private function audit(
        Business $business,
        int $actorUserId,
        string $stationId,
        string $action,
        ?array $before,
        ?array $after,
    ): void {
        DB::table('business_configuration_audits')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'location_id' => null,
            'performed_by_user_id' => $actorUserId,
            'entity_type' => 'preparation_station',
            'entity_id' => $stationId,
            'action' => $action,
            'previous_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'new_state' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

namespace App\Services\Operations;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageVenueConfiguration
{
    public function saveArea(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $this->lockBusiness($business);
            $location = $this->location($business, (string) $data['location_id']);

            $area = null;
            if (! empty($data['id'])) {
                $area = DB::table('venue_areas')
                    ->where('business_id', $business->getKey())
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();

                abort_unless($area, 404);

                if ((string) $area->location_id !== (string) $location->id) {
                    throw ValidationException::withMessages([
                        'location_id' => 'An existing area cannot be moved to another location.',
                    ]);
                }
            }

            $name = trim($data['name']);
            $this->assertUniqueAreaName($business, $location->id, $name, $area?->id);

            if ($area) {
                $before = $this->areaState($area);
                DB::table('venue_areas')
                    ->where('business_id', $business->getKey())
                    ->where('id', $area->id)
                    ->update([
                        'name' => $name,
                        'sort_order' => (int) $data['sort_order'],
                        'updated_at' => now(),
                    ]);

                $updated = $this->area($business, (string) $area->id);
                $after = $this->areaState($updated);

                if ($before !== $after) {
                    $this->audit($business, $location->id, $actorUserId, 'venue_area', $area->id, 'updated', $before, $after);
                }

                return $this->normalizeArea($updated);
            }

            $id = (string) Str::ulid();
            DB::table('venue_areas')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'location_id' => $location->id,
                'name' => $name,
                'sort_order' => (int) $data['sort_order'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created = $this->area($business, $id);
            $this->audit($business, $location->id, $actorUserId, 'venue_area', $id, 'created', null, $this->areaState($created));

            return $this->normalizeArea($created);
        }, 3);
    }

    public function setAreaStatus(Business $business, string $areaId, bool $isActive, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $areaId, $isActive, $actorUserId): object {
            $this->lockBusiness($business);

            $area = DB::table('venue_areas')
                ->where('business_id', $business->getKey())
                ->where('id', $areaId)
                ->lockForUpdate()
                ->first();

            abort_unless($area, 404);

            if ((bool) $area->is_active === $isActive) {
                return $this->normalizeArea($area);
            }

            if (! $isActive) {
                $hasActiveTables = DB::table('venue_tables')
                    ->where('business_id', $business->getKey())
                    ->where('venue_area_id', $areaId)
                    ->where('is_active', true)
                    ->exists();

                if ($hasActiveTables) {
                    throw ValidationException::withMessages([
                        'area' => 'Disable or move every active table before disabling this area.',
                    ]);
                }
            }

            $before = $this->areaState($area);

            DB::table('venue_areas')
                ->where('business_id', $business->getKey())
                ->where('id', $areaId)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            $updated = $this->area($business, $areaId);
            $this->audit($business, $area->location_id, $actorUserId, 'venue_area', $areaId, 'status_changed', $before, $this->areaState($updated));

            return $this->normalizeArea($updated);
        }, 3);
    }

    public function saveTable(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $this->lockBusiness($business);
            $location = $this->location($business, (string) $data['location_id']);

            $table = null;
            if (! empty($data['id'])) {
                $table = DB::table('venue_tables')
                    ->where('business_id', $business->getKey())
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();

                abort_unless($table, 404);

                if ((string) $table->location_id !== (string) $location->id) {
                    throw ValidationException::withMessages([
                        'location_id' => 'An existing table cannot be moved to another location.',
                    ]);
                }
            }

            $area = DB::table('venue_areas')
                ->where('business_id', $business->getKey())
                ->where('location_id', $location->id)
                ->where('id', $data['venue_area_id'])
                ->lockForUpdate()
                ->first();

            if (! $area) {
                throw ValidationException::withMessages([
                    'venue_area_id' => 'The selected area does not belong to this location.',
                ]);
            }

            $keepsInactiveAreaSafely = $table
                && ! (bool) $area->is_active
                && (string) $table->venue_area_id === (string) $area->id
                && ! (bool) $table->is_active;

            if (! (bool) $area->is_active && ! $keepsInactiveAreaSafely) {
                throw ValidationException::withMessages([
                    'venue_area_id' => 'Select an active area, or keep this inactive table in its existing inactive area.',
                ]);
            }

            $name = trim($data['name']);
            $this->assertUniqueTableName($business, $location->id, $name, $table?->id);

            $payload = [
                'venue_area_id' => $area->id,
                'name' => $name,
                'capacity' => (int) $data['capacity'],
                'updated_at' => now(),
            ];

            if ($table) {
                $hasOpenOrders = DB::table('orders')
                    ->where('business_id', $business->getKey())
                    ->where('venue_table_id', $table->id)
                    ->whereIn('status', ['open', 'payment_due'])
                    ->exists();

                if ($hasOpenOrders) {
                    throw ValidationException::withMessages([
                        'table' => 'Close or move every active order before editing this table configuration.',
                    ]);
                }

                $before = $this->tableState($table);
                DB::table('venue_tables')
                    ->where('business_id', $business->getKey())
                    ->where('id', $table->id)
                    ->update($payload);

                $updated = $this->table($business, (string) $table->id);
                $after = $this->tableState($updated);

                if ($before !== $after) {
                    $this->audit($business, $location->id, $actorUserId, 'venue_table', $table->id, 'updated', $before, $after);
                }

                return $this->normalizeTable($updated);
            }

            $id = (string) Str::ulid();
            DB::table('venue_tables')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'location_id' => $location->id,
                ...$payload,
                'is_active' => true,
                'created_at' => now(),
            ]);

            $created = $this->table($business, $id);
            $this->audit($business, $location->id, $actorUserId, 'venue_table', $id, 'created', null, $this->tableState($created));

            return $this->normalizeTable($created);
        }, 3);
    }

    public function setTableStatus(Business $business, string $tableId, bool $isActive, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $tableId, $isActive, $actorUserId): object {
            $this->lockBusiness($business);

            $table = DB::table('venue_tables')
                ->where('business_id', $business->getKey())
                ->where('id', $tableId)
                ->lockForUpdate()
                ->first();

            abort_unless($table, 404);

            if ((bool) $table->is_active === $isActive) {
                return $this->normalizeTable($table);
            }

            if ($isActive) {
                $area = DB::table('venue_areas')
                    ->where('business_id', $business->getKey())
                    ->where('location_id', $table->location_id)
                    ->where('id', $table->venue_area_id)
                    ->lockForUpdate()
                    ->first();

                if (! $area || ! (bool) $area->is_active) {
                    throw ValidationException::withMessages([
                        'table' => 'Activate the table area before enabling this table.',
                    ]);
                }
            } else {
                $hasOpenOrders = DB::table('orders')
                    ->where('business_id', $business->getKey())
                    ->where('venue_table_id', $tableId)
                    ->whereIn('status', ['open', 'payment_due'])
                    ->exists();

                if ($hasOpenOrders) {
                    throw ValidationException::withMessages([
                        'table' => 'Close or move every active order before disabling this table.',
                    ]);
                }
            }

            $before = $this->tableState($table);

            DB::table('venue_tables')
                ->where('business_id', $business->getKey())
                ->where('id', $tableId)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            $updated = $this->table($business, $tableId);
            $this->audit($business, $table->location_id, $actorUserId, 'venue_table', $tableId, 'status_changed', $before, $this->tableState($updated));

            return $this->normalizeTable($updated);
        }, 3);
    }

    public function saveRegister(Business $business, array $data, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $data, $actorUserId): object {
            $this->lockBusiness($business);
            $location = $this->location($business, (string) $data['location_id']);

            $register = null;
            if (! empty($data['id'])) {
                $register = DB::table('cash_registers')
                    ->where('business_id', $business->getKey())
                    ->where('id', $data['id'])
                    ->lockForUpdate()
                    ->first();

                abort_unless($register, 404);

                if ((string) $register->location_id !== (string) $location->id) {
                    throw ValidationException::withMessages([
                        'location_id' => 'An existing cash register cannot be moved to another location.',
                    ]);
                }
            }

            $name = trim($data['name']);
            $code = strtoupper(trim($data['code']));
            $this->assertUniqueRegisterIdentity($business, $location->id, $name, $code, $register?->id);

            $payload = [
                'name' => $name,
                'code' => $code,
                'updated_at' => now(),
            ];

            if ($register) {
                $hasOpenSession = DB::table('cash_sessions')
                    ->where('business_id', $business->getKey())
                    ->where('cash_register_id', $register->id)
                    ->where('status', 'open')
                    ->exists();

                if ($hasOpenSession) {
                    throw ValidationException::withMessages([
                        'register' => 'Close the open cash session before editing this register identity.',
                    ]);
                }

                $before = $this->registerState($register);
                DB::table('cash_registers')
                    ->where('business_id', $business->getKey())
                    ->where('id', $register->id)
                    ->update($payload);

                $updated = $this->register($business, (string) $register->id);
                $after = $this->registerState($updated);

                if ($before !== $after) {
                    $this->audit($business, $location->id, $actorUserId, 'cash_register', $register->id, 'updated', $before, $after);
                }

                return $this->normalizeRegister($updated);
            }

            $id = (string) Str::ulid();
            DB::table('cash_registers')->insert([
                'id' => $id,
                'business_id' => $business->getKey(),
                'location_id' => $location->id,
                ...$payload,
                'is_active' => true,
                'created_at' => now(),
            ]);

            $created = $this->register($business, $id);
            $this->invalidateFiscalPreflight($business);
            $this->audit($business, $location->id, $actorUserId, 'cash_register', $id, 'created', null, $this->registerState($created));

            return $this->normalizeRegister($created);
        }, 3);
    }

    public function setRegisterStatus(Business $business, string $registerId, bool $isActive, int $actorUserId): object
    {
        return DB::transaction(function () use ($business, $registerId, $isActive, $actorUserId): object {
            $this->lockBusiness($business);

            $register = DB::table('cash_registers')
                ->where('business_id', $business->getKey())
                ->where('id', $registerId)
                ->lockForUpdate()
                ->first();

            abort_unless($register, 404);

            if ((bool) $register->is_active === $isActive) {
                return $this->normalizeRegister($register);
            }

            if (! $isActive) {
                $hasOpenSession = DB::table('cash_sessions')
                    ->where('business_id', $business->getKey())
                    ->where('cash_register_id', $registerId)
                    ->where('status', 'open')
                    ->exists();

                if ($hasOpenSession) {
                    throw ValidationException::withMessages([
                        'register' => 'Close the open cash session before disabling this register.',
                    ]);
                }
            }

            $before = $this->registerState($register);

            DB::table('cash_registers')
                ->where('business_id', $business->getKey())
                ->where('id', $registerId)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            $updated = $this->register($business, $registerId);
            $this->invalidateFiscalPreflight($business);
            $this->audit($business, $register->location_id, $actorUserId, 'cash_register', $registerId, 'status_changed', $before, $this->registerState($updated));

            return $this->normalizeRegister($updated);
        }, 3);
    }

    private function lockBusiness(Business $business): void
    {
        DB::table('businesses')
            ->where('id', $business->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function location(Business $business, string $locationId): object
    {
        $location = DB::table('locations')
            ->where('business_id', $business->getKey())
            ->where('id', $locationId)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location does not belong to this business.',
            ]);
        }

        return $location;
    }

    private function area(Business $business, string $areaId): object
    {
        return DB::table('venue_areas')
            ->where('business_id', $business->getKey())
            ->where('id', $areaId)
            ->firstOrFail();
    }

    private function table(Business $business, string $tableId): object
    {
        return DB::table('venue_tables')
            ->where('business_id', $business->getKey())
            ->where('id', $tableId)
            ->firstOrFail();
    }

    private function register(Business $business, string $registerId): object
    {
        return DB::table('cash_registers')
            ->where('business_id', $business->getKey())
            ->where('id', $registerId)
            ->firstOrFail();
    }

    private function assertUniqueAreaName(Business $business, string $locationId, string $name, ?string $exceptId): void
    {
        $query = DB::table('venue_areas')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'An area with this name already exists at the location.',
            ]);
        }
    }

    private function assertUniqueTableName(Business $business, string $locationId, string $name, ?string $exceptId): void
    {
        $query = DB::table('venue_tables')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A table with this name already exists at the location.',
            ]);
        }
    }

    private function assertUniqueRegisterIdentity(
        Business $business,
        string $locationId,
        string $name,
        string $code,
        ?string $exceptId,
    ): void {
        $nameQuery = DB::table('cash_registers')
            ->where('business_id', $business->getKey())
            ->where('location_id', $locationId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)]);

        $codeQuery = DB::table('cash_registers')
            ->where('business_id', $business->getKey())
            ->whereRaw('LOWER(code) = ?', [Str::lower($code)]);

        if ($exceptId) {
            $nameQuery->where('id', '!=', $exceptId);
            $codeQuery->where('id', '!=', $exceptId);
        }

        if ($nameQuery->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A cash register with this name already exists at the location.',
            ]);
        }

        if ($codeQuery->exists()) {
            throw ValidationException::withMessages([
                'code' => 'A cash register with this code already exists in the business.',
            ]);
        }
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

    private function areaState(object $area): array
    {
        return [
            'location_id' => $area->location_id,
            'name' => $area->name,
            'sort_order' => (int) $area->sort_order,
            'is_active' => (bool) $area->is_active,
        ];
    }

    private function tableState(object $table): array
    {
        return [
            'location_id' => $table->location_id,
            'venue_area_id' => $table->venue_area_id,
            'name' => $table->name,
            'capacity' => (int) $table->capacity,
            'is_active' => (bool) $table->is_active,
        ];
    }

    private function registerState(object $register): array
    {
        return [
            'location_id' => $register->location_id,
            'name' => $register->name,
            'code' => $register->code,
            'is_active' => (bool) $register->is_active,
        ];
    }

    private function normalizeArea(object $area): object
    {
        $area->sort_order = (int) $area->sort_order;
        $area->is_active = (bool) $area->is_active;

        return $area;
    }

    private function normalizeTable(object $table): object
    {
        $table->capacity = (int) $table->capacity;
        $table->is_active = (bool) $table->is_active;

        return $table;
    }

    private function normalizeRegister(object $register): object
    {
        $register->is_active = (bool) $register->is_active;

        return $register;
    }

    private function audit(
        Business $business,
        ?string $locationId,
        int $actorUserId,
        string $entityType,
        string $entityId,
        string $action,
        ?array $previousState,
        ?array $newState,
    ): void {
        DB::table('business_configuration_audits')->insert([
            'id' => (string) Str::ulid(),
            'business_id' => $business->getKey(),
            'location_id' => $locationId,
            'performed_by_user_id' => $actorUserId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'previous_state' => $previousState === null ? null : json_encode($previousState, JSON_THROW_ON_ERROR),
            'new_state' => $newState === null ? null : json_encode($newState, JSON_THROW_ON_ERROR),
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

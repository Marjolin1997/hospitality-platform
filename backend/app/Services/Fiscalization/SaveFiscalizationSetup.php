<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveFiscalizationSetup
{
    public function execute(Business $business, array $payload, ?int $actorUserId = null): array
    {
        try {
            return DB::transaction(function () use ($business, $payload, $actorUserId): array {
                DB::table('businesses')->where('id', $business->id)->lockForUpdate()->firstOrFail();

                $before = $this->auditState($business);

                foreach ($payload['locations'] ?? [] as $row) {
                    $updated = DB::table('locations')
                        ->where('business_id', $business->id)
                        ->where('id', $row['id'])
                        ->update([
                            'fiscal_business_unit_code' => $row['fiscal_business_unit_code'] ?? null,
                            'updated_at' => now(),
                        ]);

                    if ($updated === 0 && ! DB::table('locations')->where('business_id', $business->id)->where('id', $row['id'])->exists()) {
                        throw ValidationException::withMessages(['locations' => 'A location does not belong to the active business.']);
                    }
                }

                foreach ($payload['cash_registers'] ?? [] as $row) {
                    $updated = DB::table('cash_registers')
                        ->where('business_id', $business->id)
                        ->where('id', $row['id'])
                        ->update([
                            'fiscal_tcr_code' => $row['fiscal_tcr_code'] ?? null,
                            'updated_at' => now(),
                        ]);

                    if ($updated === 0 && ! DB::table('cash_registers')->where('business_id', $business->id)->where('id', $row['id'])->exists()) {
                        throw ValidationException::withMessages(['cash_registers' => 'A cash register does not belong to the active business.']);
                    }
                }

                foreach ($payload['operators'] ?? [] as $row) {
                    $exists = DB::table('business_user')
                        ->where('business_id', $business->id)
                        ->where('user_id', $row['user_id'])
                        ->exists();

                    if (! $exists) {
                        throw ValidationException::withMessages(['operators' => 'An operator does not belong to the active business.']);
                    }

                    DB::table('business_user')
                        ->where('business_id', $business->id)
                        ->where('user_id', $row['user_id'])
                        ->update(['fiscal_operator_code' => $row['fiscal_operator_code'] ?? null]);
                }

                DB::table('fiscalization_profiles')
                    ->where('business_id', $business->id)
                    ->update([
                        'preflight_checked_at' => null,
                        'preflight_status' => null,
                        'updated_at' => now(),
                    ]);

                $result = $this->read($business);
                $after = $this->auditState($business);

                if ($actorUserId !== null && $before !== $after) {
                    DB::table('business_configuration_audits')->insert([
                        'id' => (string) Str::ulid(),
                        'business_id' => $business->id,
                        'location_id' => null,
                        'performed_by_user_id' => $actorUserId,
                        'entity_type' => 'fiscalization_setup',
                        'entity_id' => (string) $business->id,
                        'action' => 'updated',
                        'previous_state' => json_encode($before, JSON_THROW_ON_ERROR),
                        'new_state' => json_encode($after, JSON_THROW_ON_ERROR),
                        'performed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $result;
            }, attempts: 3);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Fiscal business-unit, TCR, and operator codes must be unique inside the business.',
                ]);
            }

            throw $e;
        }
    }

    private function auditState(Business $business): array
    {
        return [
            'locations' => DB::table('locations')
                ->where('business_id', $business->id)
                ->orderBy('id')
                ->get(['id', 'fiscal_business_unit_code'])
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'fiscal_business_unit_code' => $row->fiscal_business_unit_code,
                ])
                ->values()
                ->all(),
            'cash_registers' => DB::table('cash_registers')
                ->where('business_id', $business->id)
                ->orderBy('id')
                ->get(['id', 'fiscal_tcr_code'])
                ->map(fn (object $row): array => [
                    'id' => $row->id,
                    'fiscal_tcr_code' => $row->fiscal_tcr_code,
                ])
                ->values()
                ->all(),
            'operators' => DB::table('business_user')
                ->where('business_id', $business->id)
                ->orderBy('user_id')
                ->get(['user_id', 'fiscal_operator_code'])
                ->map(fn (object $row): array => [
                    'user_id' => (int) $row->user_id,
                    'fiscal_operator_code' => $row->fiscal_operator_code,
                ])
                ->values()
                ->all(),
        ];
    }

    public function read(Business $business): array
    {
        $locations = DB::table('locations')
            ->where('business_id', $business->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get(['id','name','code','is_active','fiscal_business_unit_code']);

        $registers = DB::table('cash_registers as cr')
            ->join('locations as l', function ($join) use ($business): void {
                $join->on('l.id','=','cr.location_id')->where('l.business_id',$business->id);
            })
            ->where('cr.business_id', $business->id)
            ->orderBy('l.name')->orderBy('cr.name')
            ->get([
                'cr.id','cr.location_id','cr.name','cr.code','cr.is_active','cr.fiscal_tcr_code',
                'l.name as location_name','l.is_active as location_is_active',
            ]);

        $locations->transform(function (object $row): object {
            $row->is_active = (bool) $row->is_active;

            return $row;
        });

        $registers->transform(function (object $row): object {
            $row->is_active = (bool) $row->is_active;
            $row->location_is_active = (bool) $row->location_is_active;

            return $row;
        });

        $operators = DB::table('business_user as bu')
            ->join('users as u','u.id','=','bu.user_id')
            ->leftJoin('roles as r', function ($join) use ($business): void {
                $join->on('r.id','=','bu.role_id')->where('r.business_id',$business->id);
            })
            ->where('bu.business_id', $business->id)
            ->where('bu.status', 'active')
            ->orderBy('u.name')
            ->get([
                'u.id as user_id','u.name','u.email','r.name as role_name','bu.fiscal_operator_code',
            ]);

        return [
            'locations' => $locations,
            'cash_registers' => $registers,
            'operators' => $operators,
            'summary' => [
                'locations_ready' => $locations->filter(fn ($row) => (bool) $row->is_active && filled($row->fiscal_business_unit_code))->count(),
                'locations_total' => $locations->filter(fn ($row) => (bool) $row->is_active)->count(),
                'registers_ready' => $registers->filter(fn ($row) => (bool) $row->is_active && (bool) $row->location_is_active && filled($row->fiscal_tcr_code))->count(),
                'registers_total' => $registers->filter(fn ($row) => (bool) $row->is_active && (bool) $row->location_is_active)->count(),
                'operators_ready' => $operators->whereNotNull('fiscal_operator_code')->count(),
                'operators_total' => $operators->count(),
            ],
        ];
    }
}

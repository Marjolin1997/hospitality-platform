<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ActivateProductionFiscalization
{
    public function __construct(private readonly FiscalizationPreflight $preflight) {}

    public function execute(Business $business, User $user): FiscalizationProfile
    {
        return DB::transaction(function () use ($business, $user): FiscalizationProfile {
            DB::table('businesses')->where('id', $business->id)->lockForUpdate()->firstOrFail();

            $profile = FiscalizationProfile::query()
                ->forBusiness($business)
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();
            abort_unless($profile, 404);

            if ($profile->environment !== 'production') {
                throw ValidationException::withMessages([
                    'environment' => 'Switch the fiscalization profile to production before activation.',
                ]);
            }

            $diagnostics = $this->preflight->run($business);
            if ($diagnostics['status'] === 'blocked') {
                throw ValidationException::withMessages([
                    'fiscalization' => 'Production activation is blocked until all critical preflight checks pass.',
                ]);
            }

            if ($profile->last_test_verified_at === null) {
                throw ValidationException::withMessages([
                    'fiscalization' => 'A successful DPT TEST fiscalization is required before production activation.',
                ]);
            }

            $before = [
                'environment' => $profile->environment,
                'status' => $profile->status,
                'production_activated' => $profile->production_activated_at !== null,
                'preflight_status' => $profile->preflight_status,
            ];

            $profile->forceFill([
                'status' => 'active',
                'production_activated_at' => now(),
                'production_activated_by_user_id' => $user->id,
                'preflight_status' => $diagnostics['status'],
                'preflight_checked_at' => now(),
            ])->save();

            $saved = $profile->refresh();

            DB::table('business_configuration_audits')->insert([
                'id' => (string) Str::ulid(),
                'business_id' => $business->id,
                'location_id' => null,
                'performed_by_user_id' => $user->id,
                'entity_type' => 'fiscalization_activation',
                'entity_id' => (string) $business->id,
                'action' => 'production_activated',
                'previous_state' => json_encode($before, JSON_THROW_ON_ERROR),
                'new_state' => json_encode([
                    'environment' => $saved->environment,
                    'status' => $saved->status,
                    'production_activated' => true,
                    'preflight_status' => $saved->preflight_status,
                ], JSON_THROW_ON_ERROR),
                'performed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $saved;
        }, attempts: 3);
    }
}

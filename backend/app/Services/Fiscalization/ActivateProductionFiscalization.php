<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

            $profile->forceFill([
                'status' => 'active',
                'production_activated_at' => now(),
                'production_activated_by_user_id' => $user->id,
                'preflight_status' => $diagnostics['status'],
                'preflight_checked_at' => now(),
            ])->save();

            return $profile->refresh();
        }, attempts: 3);
    }
}

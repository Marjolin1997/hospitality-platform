<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use Illuminate\Validation\ValidationException;

final class FiscalizationDispatchGuard
{
    public function assertCanDispatch(Business $business): FiscalizationProfile
    {
        $profile = FiscalizationProfile::query()->forBusiness($business)->first();

        if (! $profile || ! in_array($profile->status, ['configured','active'], true)) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Fiscalization profile is not configured.',
            ]);
        }

        if ($profile->environment !== 'production') {
            return $profile;
        }

        if ($profile->status !== 'active' || $profile->production_activated_at === null) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Production fiscalization is locked until explicit production activation succeeds.',
            ]);
        }

        if (! in_array($profile->preflight_status, ['ready','warning'], true) || $profile->preflight_checked_at === null) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Run a fresh production preflight after the latest fiscal configuration changes.',
            ]);
        }

        if ($profile->certificate_not_after !== null && $profile->certificate_not_after->isPast()) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Fiscal certificate has expired. Replace it and run production preflight again.',
            ]);
        }

        $approvedEndpoint = config('fiscalization.production_endpoint');
        if (! is_string($approvedEndpoint) || $approvedEndpoint === ''
            || rtrim((string) $profile->endpoint, '/') !== rtrim($approvedEndpoint, '/')) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Configured production endpoint is not deployment-approved.',
            ]);
        }

        return $profile;
    }
}

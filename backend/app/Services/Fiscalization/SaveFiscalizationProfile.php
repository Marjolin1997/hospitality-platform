<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveFiscalizationProfile
{
    public function execute(Business $business, array $payload): FiscalizationProfile
    {
        return DB::transaction(function () use ($business, $payload): FiscalizationProfile {
            DB::table('businesses')->where('id', $business->id)->lockForUpdate()->firstOrFail();

            $profile = FiscalizationProfile::query()
                ->forBusiness($business)
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();

            $secretRef = $profile?->certificate_secret_ref;

            if (($payload['clear_certificate_reference'] ?? false) === true) {
                $secretRef = null;
            } elseif (array_key_exists('certificate_secret_ref', $payload) && $payload['certificate_secret_ref'] !== null) {
                $secretRef = $payload['certificate_secret_ref'];
            }

            $this->assertReferenceOnly($secretRef);

            $softwareCode = $payload['software_code'] ?? null;
            $endpoint = $payload['endpoint'] ?? null;
            $status = $softwareCode && $endpoint && $secretRef ? 'configured' : 'unconfigured';

            $values = [
                'provider' => $payload['provider'],
                'environment' => $payload['environment'],
                'status' => $status,
                'software_code' => $softwareCode,
                'certificate_secret_ref' => $secretRef,
                'endpoint' => $endpoint,
                'last_verified_at' => null,
            ];

            if ($profile) {
                $profile->forceFill($values)->save();
                return $profile->refresh();
            }

            return FiscalizationProfile::query()->create([
                'business_id' => $business->id,
                ...$values,
            ]);
        }, attempts: 3);
    }

    private function assertReferenceOnly(?string $reference): void
    {
        if ($reference === null) {
            return;
        }

        $upper = strtoupper($reference);
        if (str_contains($upper, 'PRIVATE KEY') || str_contains($upper, 'BEGIN CERTIFICATE') || str_contains($reference, "\n")) {
            throw ValidationException::withMessages([
                'certificate_secret_ref' => 'Raw certificates, private keys, and passwords must not be stored in the application database.',
            ]);
        }
    }
}

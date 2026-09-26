<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SaveFiscalizationProfile
{
    public function execute(Business $business, array $payload, ?int $actorUserId = null): FiscalizationProfile
    {
        return DB::transaction(function () use ($business, $payload, $actorUserId): FiscalizationProfile {
            DB::table('businesses')->where('id', $business->id)->lockForUpdate()->firstOrFail();

            $profile = FiscalizationProfile::query()
                ->forBusiness($business)
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();

            $before = $this->auditState($profile);

            $secretRef = $profile?->certificate_secret_ref;
            $passwordRef = $profile?->certificate_password_secret_ref;

            if (($payload['clear_certificate_reference'] ?? false) === true) {
                $secretRef = null;
            } elseif (array_key_exists('certificate_secret_ref', $payload) && $payload['certificate_secret_ref'] !== null) {
                $secretRef = $payload['certificate_secret_ref'];
            }

            if (($payload['clear_certificate_password_reference'] ?? false) === true) {
                $passwordRef = null;
            } elseif (array_key_exists('certificate_password_secret_ref', $payload) && $payload['certificate_password_secret_ref'] !== null) {
                $passwordRef = $payload['certificate_password_secret_ref'];
            }

            $this->assertReferenceOnly($secretRef);
            $this->assertReferenceOnly($passwordRef);

            $provider = $payload['provider'];
            $environment = $payload['environment'];
            $softwareCode = $payload['software_code'] ?? null;
            $endpoint = $payload['endpoint'] ?? null;
            $isIssuerInVat = array_key_exists('is_issuer_in_vat', $payload)
                ? $payload['is_issuer_in_vat']
                : $profile?->is_issuer_in_vat;

            $lastTestVerifiedAt = $profile?->last_test_verified_at;
            $lastProductionVerifiedAt = $profile?->last_production_verified_at;
            $productionActivatedAt = $profile?->production_activated_at;
            $productionActivatedBy = $profile?->production_activated_by_user_id;

            $identityChanged = $profile && (
                $profile->provider !== $provider
                || (string) $profile->software_code !== (string) $softwareCode
                || (string) $profile->certificate_secret_ref !== (string) $secretRef
                || (string) $profile->certificate_password_secret_ref !== (string) $passwordRef
                || $profile->is_issuer_in_vat !== $isIssuerInVat
            );

            $environmentChanged = $profile && $profile->environment !== $environment;
            $endpointChangedInSameEnvironment = $profile
                && ! $environmentChanged
                && rtrim((string) $profile->endpoint, '/') !== rtrim((string) $endpoint, '/');

            if ($identityChanged) {
                $lastTestVerifiedAt = null;
                $lastProductionVerifiedAt = null;
                $productionActivatedAt = null;
                $productionActivatedBy = null;
            } elseif ($endpointChangedInSameEnvironment) {
                if ($environment === 'test') {
                    $lastTestVerifiedAt = null;
                } else {
                    $lastProductionVerifiedAt = null;
                    $productionActivatedAt = null;
                    $productionActivatedBy = null;
                }
            }

            if ($environmentChanged) {
                // Switching environments always requires an explicit production activation
                // before any PRODUCTION invoice can leave the platform.
                $productionActivatedAt = null;
                $productionActivatedBy = null;
            }

            $configured = $softwareCode && $endpoint && $secretRef && $isIssuerInVat !== null;
            $status = 'unconfigured';

            if ($configured) {
                if ($environment === 'production') {
                    $status = $productionActivatedAt ? 'active' : 'configured';
                } else {
                    $status = $lastTestVerifiedAt ? 'active' : 'configured';
                }
            }

            $lastVerifiedAt = $environment === 'production'
                ? $lastProductionVerifiedAt
                : $lastTestVerifiedAt;

            $values = [
                'provider' => $provider,
                'environment' => $environment,
                'status' => $status,
                'software_code' => $softwareCode,
                'is_issuer_in_vat' => $isIssuerInVat,
                'certificate_secret_ref' => $secretRef,
                'certificate_password_secret_ref' => $passwordRef,
                'endpoint' => $endpoint,
                'last_verified_at' => $lastVerifiedAt,
                'last_test_verified_at' => $lastTestVerifiedAt,
                'last_production_verified_at' => $lastProductionVerifiedAt,
                'production_activated_at' => $productionActivatedAt,
                'production_activated_by_user_id' => $productionActivatedBy,
                'preflight_checked_at' => null,
                'preflight_status' => null,
                'certificate_not_before' => null,
                'certificate_not_after' => null,
                'certificate_fingerprint_sha256' => null,
            ];

            if ($profile) {
                $profile->forceFill($values)->save();
                $saved = $profile->refresh();
            } else {
                $saved = FiscalizationProfile::query()->create([
                    'business_id' => $business->id,
                    ...$values,
                ]);
            }

            $after = $this->auditState($saved);
            if ($actorUserId !== null && $before !== $after) {
                DB::table('business_configuration_audits')->insert([
                    'id' => (string) Str::ulid(),
                    'business_id' => $business->id,
                    'location_id' => null,
                    'performed_by_user_id' => $actorUserId,
                    'entity_type' => 'fiscalization_profile',
                    'entity_id' => (string) $business->id,
                    'action' => $before === null ? 'created' : 'updated',
                    'previous_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                    'new_state' => json_encode($after, JSON_THROW_ON_ERROR),
                    'performed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $saved;
        }, attempts: 3);
    }

    private function auditState(?FiscalizationProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }

        return [
            'provider' => $profile->provider,
            'environment' => $profile->environment,
            'status' => $profile->status,
            'software_code' => $profile->software_code,
            'is_issuer_in_vat' => $profile->is_issuer_in_vat,
            'endpoint' => $profile->endpoint,
            'certificate_reference_configured' => filled($profile->certificate_secret_ref),
            'certificate_password_reference_configured' => filled($profile->certificate_password_secret_ref),
            'production_activated' => $profile->production_activated_at !== null,
        ];
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

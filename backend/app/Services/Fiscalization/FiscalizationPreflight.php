<?php

namespace App\Services\Fiscalization;

use App\Models\Business;
use App\Models\FiscalizationProfile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class FiscalizationPreflight
{
    public function __construct(private readonly FiscalCertificateInspector $certificates) {}

    public function run(Business $business): array
    {
        $profile = FiscalizationProfile::query()->forBusiness($business)->first();
        $checks = [];
        $certificate = null;

        $this->check($checks, 'profile', 'Fiscalization profile', $profile !== null, true,
            $profile ? 'Fiscalization profile exists.' : 'Fiscalization profile is missing.');

        if (! $profile) {
            return $this->result($business, null, $checks, null);
        }

        $this->check($checks, 'tax_number', 'Business NUIS/NIPT',
            is_string($business->tax_number) && preg_match('/^[A-Za-z][0-9]{8}[A-Za-z]$/', $business->tax_number) === 1,
            true,
            'Business fiscal identity must use a valid Albanian NUIS/NIPT.');

        $this->check($checks, 'software_code', 'Certified software code',
            is_string($profile->software_code) && preg_match('/^[a-z]{2}[0-9]{3}[a-z]{2}[0-9]{3}$/', $profile->software_code) === 1,
            true,
            'Software code must be configured in the certified 10-character format.');

        $endpointOk = is_string($profile->endpoint) && str_starts_with(strtolower($profile->endpoint), 'https://');
        $this->check($checks, 'endpoint_https', 'HTTPS fiscal endpoint', $endpointOk, true,
            $endpointOk ? 'Fiscal endpoint uses HTTPS.' : 'Fiscal endpoint must use HTTPS.');

        $expectedEndpoint = $profile->environment === 'production'
            ? config('fiscalization.production_endpoint')
            : config('fiscalization.test_endpoint');

        if (is_string($expectedEndpoint) && $expectedEndpoint !== '') {
            $this->check($checks, 'endpoint_expected', 'Expected DPT endpoint',
                rtrim((string) $profile->endpoint, '/') === rtrim($expectedEndpoint, '/'),
                true,
                'Configured endpoint must match the deployment-approved DPT endpoint.');
        } else {
            $this->check($checks, 'endpoint_expected', 'Expected DPT endpoint',
                $profile->environment !== 'production',
                $profile->environment === 'production',
                $profile->environment === 'production'
                    ? 'Production endpoint allowlist is not configured in the deployment.'
                    : 'No TEST endpoint allowlist is configured; verify it before real DPT testing.',
                $profile->environment === 'production' ? 'fail' : 'warning');
        }

        $this->check($checks, 'issuer_vat', 'Issuer VAT registration',
            $profile->is_issuer_in_vat !== null,
            true,
            'Issuer VAT registration must be explicitly configured.');

        $this->check($checks, 'certificate_reference', 'Certificate reference',
            filled($profile->certificate_secret_ref),
            true,
            'A secure PKCS#12 certificate reference is required.');

        if (filled($profile->certificate_secret_ref)) {
            try {
                $certificate = $this->certificates->inspect(
                    (string) $profile->certificate_secret_ref,
                    $profile->certificate_password_secret_ref,
                );

                $this->check($checks, 'certificate_key_match', 'Certificate/private key match',
                    $certificate['private_key_matches'], true,
                    $certificate['private_key_matches']
                        ? 'Certificate matches its private key.'
                        : 'Certificate does not match the configured private key.');

                $this->check($checks, 'certificate_validity', 'Certificate validity',
                    $certificate['valid_now'], true,
                    $certificate['valid_now']
                        ? 'Certificate is currently valid.'
                        : 'Certificate is expired or not valid yet.');

                if ($certificate['valid_now'] && $certificate['days_remaining'] <= 30) {
                    $this->check($checks, 'certificate_expiry', 'Certificate expiry',
                        false, false,
                        "Certificate expires in {$certificate['days_remaining']} days.",
                        'warning');
                } else {
                    $this->check($checks, 'certificate_expiry', 'Certificate expiry',
                        true, false,
                        "Certificate has {$certificate['days_remaining']} days remaining.");
                }
            } catch (Throwable $e) {
                $this->check($checks, 'certificate_load', 'Certificate load',
                    false, true,
                    'Certificate could not be loaded from the configured secret reference.');
            }
        }

        $activeLocations = DB::table('locations')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->get(['id','fiscal_business_unit_code']);
        $locationsReady = $activeLocations->filter(fn ($row) => filled($row->fiscal_business_unit_code))->count();
        $this->check($checks, 'business_units', 'Active business units',
            $activeLocations->count() > 0 && $locationsReady === $activeLocations->count(),
            true,
            "{$locationsReady}/{$activeLocations->count()} active locations have DPT business-unit codes.");

        $activeRegisters = DB::table('cash_registers')
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->get(['id','fiscal_tcr_code']);
        $registersReady = $activeRegisters->filter(fn ($row) => filled($row->fiscal_tcr_code))->count();
        $tcrComplete = $activeRegisters->count() === 0 || $registersReady === $activeRegisters->count();
        $this->check($checks, 'tcr_registers', 'Active TCR registers',
            $tcrComplete,
            $profile->environment === 'production',
            "{$registersReady}/{$activeRegisters->count()} active registers have TCR codes.",
            ! $tcrComplete && $profile->environment !== 'production' ? 'warning' : null);

        $fiscalIssuers = DB::table('business_user as bu')
            ->join('roles as r', function ($join) use ($business): void {
                $join->on('r.id','=','bu.role_id')
                    ->where('r.business_id',$business->id);
            })
            ->join('permission_role as pr','pr.role_id','=','r.id')
            ->join('permissions as p','p.id','=','pr.permission_id')
            ->where('bu.business_id', $business->id)
            ->where('bu.status', 'active')
            ->where('p.key', 'fiscalization.issue')
            ->distinct()
            ->get(['bu.user_id','bu.fiscal_operator_code']);

        $issuersReady = $fiscalIssuers->filter(fn ($row) => filled($row->fiscal_operator_code))->count();
        $this->check($checks, 'operators', 'Authorized fiscal operators',
            $fiscalIssuers->count() > 0 && $issuersReady === $fiscalIssuers->count(),
            true,
            "{$issuersReady}/{$fiscalIssuers->count()} users authorized to fiscalize documents have DPT operator codes.");

        if ($profile->environment === 'production') {
            $this->check($checks, 'test_verified', 'Successful TEST verification',
                $profile->last_test_verified_at !== null,
                true,
                $profile->last_test_verified_at
                    ? 'A successful TEST fiscalization has been recorded.'
                    : 'At least one successful TEST fiscalization is required before production activation.');

            $caBundle = config('fiscalization.dpt_ca_bundle');
            $caReadable = is_string($caBundle) && $caBundle !== '' && is_readable($caBundle);
            $this->check($checks, 'dpt_ca_bundle', 'DPT/AKSHI CA trust bundle',
                $caReadable,
                true,
                $caReadable
                    ? 'Production response trust bundle is available.'
                    : 'Production requires a readable DPT/AKSHI CA trust bundle.');

            $this->check($checks, 'queue_driver', 'Asynchronous fiscal queue',
                config('queue.default') !== 'sync',
                true,
                config('queue.default') !== 'sync'
                    ? 'Fiscalization runs through an asynchronous queue.'
                    : 'Production fiscalization cannot use the synchronous queue driver.');
        }

        return $this->result($business, $profile, $checks, $certificate);
    }

    private function check(
        array &$checks,
        string $key,
        string $label,
        bool $passed,
        bool $blocking,
        string $message,
        ?string $forcedStatus = null,
    ): void {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'status' => $forcedStatus ?? ($passed ? 'pass' : ($blocking ? 'fail' : 'warning')),
            'blocking' => $blocking,
            'message' => $message,
        ];
    }

    private function result(Business $business, ?FiscalizationProfile $profile, array $checks, ?array $certificate): array
    {
        $blockingFailures = collect($checks)->filter(fn ($check) => $check['status'] === 'fail' && $check['blocking'])->count();
        $warnings = collect($checks)->where('status', 'warning')->count();

        $status = $blockingFailures > 0 ? 'blocked' : ($warnings > 0 ? 'warning' : 'ready');

        if ($profile) {
            $profile->forceFill([
                'preflight_checked_at' => now(),
                'preflight_status' => $status,
                'certificate_not_before' => $certificate['not_before'] ?? null,
                'certificate_not_after' => $certificate['not_after'] ?? null,
                'certificate_fingerprint_sha256' => $certificate['fingerprint_sha256'] ?? null,
            ])->save();
        }

        return [
            'status' => $status,
            'blocking_failures' => $blockingFailures,
            'warnings' => $warnings,
            'environment' => $profile?->environment,
            'can_fiscalize_test' => $status !== 'blocked' && $profile?->environment === 'test',
            'can_activate_production' => $status !== 'blocked' && $profile?->environment === 'production',
            'production_active' => $profile?->production_activated_at !== null,
            'checked_at' => now()->toISOString(),
            'checks' => $checks,
            'certificate' => $certificate,
            'business_id' => $business->id,
        ];
    }
}

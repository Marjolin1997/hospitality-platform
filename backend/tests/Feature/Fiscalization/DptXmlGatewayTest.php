<?php

use App\Models\FiscalizationProfile;
use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use App\Services\Fiscalization\DirectDptGateway;
use App\Services\Fiscalization\DptRegisterInvoiceXmlBuilder;
use App\Services\Fiscalization\FiscalXmlSignatureVerifier;
use App\Services\Fiscalization\FiscalXmlSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function dptTestCredentials(string $commonName = 'GDT eFiskalizimi Test'): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    $dn = [
        'countryName' => 'AL',
        'organizationName' => 'Test Fiscal Authority',
        'commonName' => $commonName,
    ];
    $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
    $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);

    $privateKeyPem = '';
    $certificatePem = '';
    openssl_pkey_export($key, $privateKeyPem);
    openssl_x509_export($certificate, $certificatePem);

    $pkcs12 = '';
    openssl_pkcs12_export($certificate, $pkcs12, $key, 'test-password');

    return [
        'private_key_pem' => $privateKeyPem,
        'certificate_pem' => $certificatePem,
        'pkcs12_base64' => base64_encode($pkcs12),
        'password' => 'test-password',
    ];
}

function dptSubmission(string $requestUuid = '11111111-2222-4333-8444-555555555555'): FiscalInvoiceSubmission
{
    return new FiscalInvoiceSubmission(
        invoiceId: '01TESTINVOICE00000000000001',
        businessId: '01TESTBUSINESS0000000000001',
        environment: 'test',
        requestUuid: $requestUuid,
        sendDateTime: '2026-09-18T14:00:00+02:00',
        issueDateTime: '2026-09-18T13:55:00+02:00',
        subsequentDeliveryType: null,
        invoiceType: 'CASH',
        invoiceNumber: '1/2026/aa123aa123',
        invoiceOrdinal: 1,
        issuerNuis: 'L12345678A',
        isIssuerInVat: true,
        businessUnitCode: 'bb123bb123',
        tcrCode: 'aa123aa123',
        operatorCode: 'cc123cc123',
        softwareCode: 'dd123dd123',
        currency: 'ALL',
        totalWithoutVat: '100.00',
        totalVat: '20.00',
        totalPrice: '120.00',
        iic: '00112233445566778899AABBCCDDEEFF',
        iicSignature: str_repeat('A1', 128),
        seller: [
            'id_type'=>'NUIS','id_num'=>'L12345678A','name'=>'Test Seller sh.p.k.','address'=>'Tirane','country'=>'ALB',
        ],
        buyer: [],
        items: [[
            'name'=>'Kafe','code'=>'KAFE','unit'=>'Copë','quantity'=>'1','unit_price_before_vat'=>'100.00',
            'unit_price_after_vat'=>'120.00','price_before_vat'=>'100.00','vat_rate'=>'20.00',
            'vat_amount'=>'20.00','price_after_vat'=>'120.00',
        ]],
        sameTaxes: [[
            'count'=>1,'price_before_vat'=>'100.00','vat_rate'=>'20.00','vat_amount'=>'20.00',
        ]],
        payments: [['type'=>'BANKNOTE','amount'=>'120.00']],
        payloadHash: hash('sha256','test-payload'),
    );
}

function dptSignedResponse(
    FiscalXmlSigner $signer,
    string $privateKeyPem,
    string $certificatePem,
    string $requestUuid,
    string $fic = 'FIC-TEST-123',
): string {
    $doc = new DOMDocument('1.0','UTF-8');
    $doc->preserveWhiteSpace = false;
    $envelope = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/','SOAP-ENV:Envelope');
    $doc->appendChild($envelope);
    $body = $doc->createElementNS('http://schemas.xmlsoap.org/soap/envelope/','SOAP-ENV:Body');
    $envelope->appendChild($body);
    $response = $doc->createElementNS('https://eFiskalizimi.tatime.gov.al/FiscalizationService/schema','RegisterInvoiceResponse');
    $response->setAttribute('Id','Response');
    $response->setAttribute('Version','3');
    $body->appendChild($response);
    $header = $doc->createElementNS('https://eFiskalizimi.tatime.gov.al/FiscalizationService/schema','Header');
    $header->setAttribute('UUID','aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    $header->setAttribute('RequestUUID',$requestUuid);
    $response->appendChild($header);
    $response->appendChild($doc->createElementNS(
        'https://eFiskalizimi.tatime.gov.al/FiscalizationService/schema',
        'FIC',
        $fic,
    ));

    return $signer->sign($doc,$response,$privateKeyPem,$certificatePem,'Response');
}

test('register invoice XML v3 is signed and preserves required fiscal facts', function (): void {
    $credentials = dptTestCredentials();
    $submission = dptSubmission();
    $builder = app(DptRegisterInvoiceXmlBuilder::class);
    $signer = app(FiscalXmlSigner::class);

    ['document'=>$document,'request'=>$request] = $builder->build($submission);
    $xml = $signer->sign(
        $document,
        $request,
        $credentials['private_key_pem'],
        $credentials['certificate_pem'],
    );

    expect($xml)->toContain('RegisterInvoiceRequest')
        ->toContain('Version="3"')
        ->toContain('InvNum="1/2026/aa123aa123"')
        ->toContain('InvOrdNum="1"')
        ->toContain('TypeOfInv="CASH"')
        ->toContain('TCRCode="aa123aa123"')
        ->toContain('Type="BANKNOTE"')
        ->toContain('VATRate="20.00"')
        ->toContain('SignatureValue');

    app(FiscalXmlSignatureVerifier::class)->verify($xml,'Request',false);
});

test('direct DPT gateway accepts only a cryptographically signed matching response', function (): void {
    $credentials = dptTestCredentials();
    putenv('DPT_TEST_P12='.$credentials['pkcs12_base64']);
    putenv('DPT_TEST_PASSWORD='.$credentials['password']);

    try {
        $submission = dptSubmission();
        $responseXml = dptSignedResponse(
            app(FiscalXmlSigner::class),
            $credentials['private_key_pem'],
            $credentials['certificate_pem'],
            $submission->requestUuid,
        );

        Http::fake(fn (Request $request) => Http::response($responseXml,200,['Content-Type'=>'text/xml']));

        $profile = new FiscalizationProfile([
            'provider'=>'direct_dpt',
            'environment'=>'test',
            'status'=>'configured',
            'endpoint'=>'https://dpt-test.example.test/FiscalizationService-v1',
            'certificate_secret_ref'=>'env:DPT_TEST_P12',
            'certificate_password_secret_ref'=>'env:DPT_TEST_PASSWORD',
        ]);

        $result = app(DirectDptGateway::class)->registerInvoice($submission,$profile);

        expect($result->successful)->toBeTrue()
            ->and($result->fic)->toBe('FIC-TEST-123')
            ->and($result->retryable)->toBeFalse();

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();
            return $request->url()==='https://dpt-test.example.test/FiscalizationService-v1'
                && str_contains($body,'RegisterInvoiceRequest')
                && str_contains($body,'SignatureValue');
        });
    } finally {
        putenv('DPT_TEST_P12');
        putenv('DPT_TEST_PASSWORD');
    }
});

test('direct DPT gateway rejects a response signed with the wrong fiscal identity', function (): void {
    $client = dptTestCredentials();
    $attacker = dptTestCredentials('Untrusted Fiscal Server');
    putenv('DPT_TEST_P12='.$client['pkcs12_base64']);
    putenv('DPT_TEST_PASSWORD='.$client['password']);

    try {
        $submission = dptSubmission();
        $responseXml = dptSignedResponse(
            app(FiscalXmlSigner::class),
            $attacker['private_key_pem'],
            $attacker['certificate_pem'],
            $submission->requestUuid,
        );
        Http::fake([ '*' => Http::response($responseXml,200,['Content-Type'=>'text/xml']) ]);

        $profile = new FiscalizationProfile([
            'environment'=>'test','status'=>'configured','endpoint'=>'https://dpt-test.example.test/service',
            'certificate_secret_ref'=>'env:DPT_TEST_P12','certificate_password_secret_ref'=>'env:DPT_TEST_PASSWORD',
        ]);

        $result = app(DirectDptGateway::class)->registerInvoice($submission,$profile);

        expect($result->successful)->toBeFalse()
            ->and($result->errorCode)->toBe('DPT_RESPONSE_INVALID')
            ->and($result->retryable)->toBeFalse();
    } finally {
        putenv('DPT_TEST_P12');
        putenv('DPT_TEST_PASSWORD');
    }
});


test('direct DPT gateway refuses an unapproved production endpoint before any network call', function (): void {
    Http::fake();

    config()->set('fiscalization.production_endpoint', 'https://approved-dpt.example.test/service');

    $profile = new FiscalizationProfile([
        'environment' => 'production',
        'status' => 'active',
        'endpoint' => 'https://unexpected.example.test/service',
        'certificate_secret_ref' => 'env:SHOULD_NOT_BE_READ',
    ]);

    $result = app(DirectDptGateway::class)->registerInvoice(dptSubmission(), $profile);

    expect($result->successful)->toBeFalse()
        ->and($result->errorCode)->toBe('DPT_PRODUCTION_ENDPOINT_MISMATCH')
        ->and($result->retryable)->toBeFalse();

    Http::assertNothingSent();
});

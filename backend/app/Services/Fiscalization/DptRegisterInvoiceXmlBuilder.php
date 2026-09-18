<?php

namespace App\Services\Fiscalization;

use App\Services\Fiscalization\Data\FiscalInvoiceSubmission;
use DOMDocument;
use DOMElement;
use RuntimeException;

final class DptRegisterInvoiceXmlBuilder
{
    public const SOAP_NS = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const FISCAL_NS = 'https://eFiskalizimi.tatime.gov.al/FiscalizationService/schema';
    public const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * @return array{document:DOMDocument,request:DOMElement}
     */
    public function build(FiscalInvoiceSubmission $submission): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $document->preserveWhiteSpace = false;

        $envelope = $document->createElementNS(self::SOAP_NS, 'SOAP-ENV:Envelope');
        $document->appendChild($envelope);
        $envelope->appendChild($document->createElementNS(self::SOAP_NS, 'SOAP-ENV:Header'));
        $body = $document->createElementNS(self::SOAP_NS, 'SOAP-ENV:Body');
        $envelope->appendChild($body);

        $request = $document->createElementNS(self::FISCAL_NS, 'RegisterInvoiceRequest');
        $request->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns2', self::DS_NS);
        $request->setAttribute('Id', 'Request');
        $request->setAttribute('Version', '3');
        $body->appendChild($request);

        $header = $document->createElementNS(self::FISCAL_NS, 'Header');
        $header->setAttribute('UUID', $submission->requestUuid);
        $header->setAttribute('SendDateTime', $submission->sendDateTime);
        if ($submission->subsequentDeliveryType) {
            $header->setAttribute('SubseqDelivType', $submission->subsequentDeliveryType);
        }
        $request->appendChild($header);

        $invoice = $document->createElementNS(self::FISCAL_NS, 'Invoice');
        foreach ([
            'TypeOfInv' => $submission->invoiceType,
            'IsSimplifiedInv' => 'false',
            'IssueDateTime' => $submission->issueDateTime,
            'InvNum' => $submission->invoiceNumber,
            'InvOrdNum' => (string) $submission->invoiceOrdinal,
            'IsIssuerInVAT' => $submission->isIssuerInVat ? 'true' : 'false',
            'IsReverseCharge' => 'false',
            'OperatorCode' => $submission->operatorCode,
            'BusinUnitCode' => $submission->businessUnitCode,
            'SoftCode' => $submission->softwareCode,
            'IIC' => $submission->iic,
            'IICSignature' => $submission->iicSignature,
            'TotPrice' => $submission->totalPrice,
            'TotPriceWoVAT' => $submission->totalWithoutVat,
            'TotVATAmt' => $submission->totalVat,
        ] as $name => $value) {
            $invoice->setAttribute($name, $value);
        }
        if ($submission->tcrCode) {
            $invoice->setAttribute('TCRCode', $submission->tcrCode);
        }
        $request->appendChild($invoice);

        $payMethods = $document->createElementNS(self::FISCAL_NS, 'PayMethods');
        foreach ($submission->payments as $payment) {
            $payMethod = $document->createElementNS(self::FISCAL_NS, 'PayMethod');
            $payMethod->setAttribute('Type', (string) $payment['type']);
            $payMethod->setAttribute('Amt', (string) $payment['amount']);
            $payMethods->appendChild($payMethod);
        }
        $invoice->appendChild($payMethods);

        $invoice->appendChild($this->party($document, 'Seller', $submission->seller, true));
        if ($submission->buyer !== []) {
            $invoice->appendChild($this->party($document, 'Buyer', $submission->buyer, false));
        }

        $items = $document->createElementNS(self::FISCAL_NS, 'Items');
        foreach ($submission->items as $item) {
            $node = $document->createElementNS(self::FISCAL_NS, 'I');
            foreach ([
                'N' => $item['name'] ?? null,
                'C' => $item['code'] ?? null,
                'U' => $item['unit'] ?? null,
                'Q' => $item['quantity'] ?? null,
                'UPB' => $item['unit_price_before_vat'] ?? null,
                'UPA' => $item['unit_price_after_vat'] ?? null,
                'R' => $item['rebate_percent'] ?? null,
                'RR' => array_key_exists('rebate_reduces_base', $item) ? (($item['rebate_reduces_base'] ?? false) ? 'true' : 'false') : null,
                'PB' => $item['price_before_vat'] ?? null,
                'VR' => $item['vat_rate'] ?? null,
                'VA' => $item['vat_amount'] ?? null,
                'PA' => $item['price_after_vat'] ?? null,
            ] as $name => $value) {
                if ($value !== null && $value !== '') {
                    $node->setAttribute($name, (string) $value);
                }
            }
            $items->appendChild($node);
        }
        $invoice->appendChild($items);

        $sameTaxes = $document->createElementNS(self::FISCAL_NS, 'SameTaxes');
        foreach ($submission->sameTaxes as $tax) {
            $node = $document->createElementNS(self::FISCAL_NS, 'SameTax');
            $node->setAttribute('NumOfItems', (string) $tax['count']);
            $node->setAttribute('PriceBefVAT', (string) $tax['price_before_vat']);
            $node->setAttribute('VATRate', (string) $tax['vat_rate']);
            $node->setAttribute('VATAmt', (string) $tax['vat_amount']);
            $sameTaxes->appendChild($node);
        }
        $invoice->appendChild($sameTaxes);

        if ($request->getAttribute('Id') !== 'Request') {
            throw new RuntimeException('Fiscal request identity was not preserved.');
        }

        return ['document' => $document, 'request' => $request];
    }

    private function party(DOMDocument $document, string $tag, array $data, bool $seller): DOMElement
    {
        $node = $document->createElementNS(self::FISCAL_NS, $tag);

        $pairs = [
            'IDType' => $data['id_type'] ?? null,
            'IDNum' => $data['id_num'] ?? null,
            'Name' => $data['name'] ?? null,
            'Address' => $data['address'] ?? null,
            'Town' => $data['town'] ?? null,
            'Country' => $data['country'] ?? null,
        ];

        foreach ($pairs as $name => $value) {
            if ($value !== null && $value !== '') {
                $node->setAttribute($name, (string) $value);
            }
        }

        if ($seller) {
            foreach (['IDType','IDNum','Name'] as $required) {
                if (! $node->hasAttribute($required)) {
                    throw new RuntimeException("Seller {$required} is required for fiscal XML.");
                }
            }
        }

        return $node;
    }
}

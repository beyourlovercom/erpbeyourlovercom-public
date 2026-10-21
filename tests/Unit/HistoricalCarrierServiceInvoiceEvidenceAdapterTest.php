<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\HistoricalCarrierServiceInvoiceEvidenceAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HistoricalCarrierServiceInvoiceEvidenceAdapterTest extends TestCase
{
    #[Test]
    public function it_approves_exact_tracking_service_when_carrier_agrees(): void
    {
        $out = (new HistoricalCarrierServiceInvoiceEvidenceAdapter)->enrich(
            $this->candidate(carrier: 'USPS'),
            $this->row('9235990412345678901234', 'USPS', 'USPS Ground Advantage')
        );

        self::assertSame('USPS', $out['evidence']['carrier']);
        self::assertSame('USPS Ground Advantage', $out['evidence']['service']);
        self::assertTrue($out['evidence']['carrier_service_approved']);
        self::assertStringContainsString('us_warehouse_invoice', $out['evidence_source']);
    }

    #[Test]
    public function it_can_fill_blank_carrier_from_exact_invoice_evidence(): void
    {
        $out = (new HistoricalCarrierServiceInvoiceEvidenceAdapter)->enrich(
            $this->candidate(carrier: null),
            $this->row('9235990412345678901234', 'FedEx', 'FedEx Home Delivery')
        );

        self::assertSame('FedEx', $out['evidence']['carrier']);
        self::assertSame('FedEx Home Delivery', $out['evidence']['service']);
        self::assertTrue($out['evidence']['carrier_service_approved']);
    }

    #[Test]
    public function it_fails_closed_on_carrier_conflict(): void
    {
        $out = (new HistoricalCarrierServiceInvoiceEvidenceAdapter)->enrich(
            $this->candidate(carrier: '4PX'),
            $this->row('9235990412345678901234', 'USPS', 'USPS Priority Mail')
        );

        self::assertTrue($out['conflicts']['source_evidence_conflict']);
        self::assertSame('4PX', $out['evidence']['carrier']);
        self::assertFalse($out['evidence']['carrier_service_approved']);
    }

    #[Test]
    public function it_fails_closed_on_conflicting_already_approved_service(): void
    {
        $candidate = $this->candidate(carrier: 'USPS');
        $candidate['evidence']['service'] = 'USPS Priority Mail';
        $candidate['evidence']['carrier_service_approved'] = true;

        $out = (new HistoricalCarrierServiceInvoiceEvidenceAdapter)->enrich(
            $candidate,
            $this->row('9235990412345678901234', 'USPS', 'USPS Ground Advantage')
        );

        self::assertTrue($out['conflicts']['source_evidence_conflict']);
        self::assertSame('USPS Priority Mail', $out['evidence']['service']);
    }

    #[Test]
    public function it_ignores_non_exact_identity_or_tracking(): void
    {
        $adapter = new HistoricalCarrierServiceInvoiceEvidenceAdapter;
        $candidate = $this->candidate(carrier: 'USPS');
        $candidate['identity']['exact'] = false;
        self::assertSame($candidate, $adapter->enrich($candidate, $this->row('9235990412345678901234', 'USPS', 'USPS Priority Mail')));

        $candidate = $this->candidate(carrier: 'USPS');
        self::assertSame($candidate, $adapter->enrich($candidate, $this->row('OTHER', 'USPS', 'USPS Priority Mail')));
    }

    #[Test]
    public function enrichment_is_idempotent(): void
    {
        $adapter = new HistoricalCarrierServiceInvoiceEvidenceAdapter;
        $row = $this->row('9235990412345678901234', 'USPS', 'USPS Ground Advantage');
        $once = $adapter->enrich($this->candidate(carrier: 'USPS'), $row);
        $twice = $adapter->enrich($once, $row);

        self::assertSame($once, $twice);
    }

    /** @return array<string,mixed> */
    private function candidate(?string $carrier): array
    {
        return [
            'candidate_key' => 'woocommerce:40000:9235990412345678901234',
            'identity' => ['exact' => true],
            'evidence_source' => 'woocommerce_tracking_meta',
            'evidence' => [
                'tracking_number' => '9235990412345678901234',
                'carrier' => $carrier,
                'service' => null,
                'carrier_service_approved' => false,
            ],
            'conflicts' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function row(string $tracking, string $carrier, string $service): array
    {
        return [
            'tracking_number' => $tracking,
            'carrier' => $carrier,
            'service' => $service,
            'source_count' => 1,
            'evidence_rows' => [[
                'invoice_sha256' => str_repeat('a', 64),
                'row_sha256' => str_repeat('b', 64),
                'invoice_name' => 'invoice.xlsx',
                'row_number' => 7,
                'order_reference' => 'HEZEB2C12345',
            ]],
        ];
    }
}

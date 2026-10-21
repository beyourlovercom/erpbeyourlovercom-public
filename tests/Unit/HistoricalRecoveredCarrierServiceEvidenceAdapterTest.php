<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\HistoricalRecoveredCarrierServiceEvidenceAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HistoricalRecoveredCarrierServiceEvidenceAdapterTest extends TestCase
{
    #[Test]
    public function it_approves_actual_ship_via_even_when_request_via_was_selected_by_warehouse(): void
    {
        $row = $this->westRow('USPS Ground Advantage');
        $row['request_via'] = 'Selected by warehouse';

        $out = (new HistoricalRecoveredCarrierServiceEvidenceAdapter)->enrichActualShipVia(
            $this->candidate(),
            $row
        );

        self::assertSame('USPS Ground Advantage', $out['evidence']['service']);
        self::assertTrue($out['evidence']['carrier_service_approved']);
        self::assertSame('Ship Via', $out['evidence']['source_us_warehouse_actual_ship_via']['service_source_field']);
        self::assertStringContainsString('us_warehouse_actual_ship_via', $out['evidence_source']);
    }

    #[Test]
    public function it_rejects_placeholder_as_actual_ship_via_service(): void
    {
        $candidate = $this->candidate();
        $out = (new HistoricalRecoveredCarrierServiceEvidenceAdapter)->enrichActualShipVia(
            $candidate,
            $this->westRow('Selected by warehouse')
        );

        self::assertSame($candidate, $out);
    }

    #[Test]
    public function it_requires_exact_tracking_for_actual_ship_via(): void
    {
        $candidate = $this->candidate();
        $row = $this->westRow('USPS Priority Mail');
        $row['tracking_number'] = 'OTHER';

        self::assertSame(
            $candidate,
            (new HistoricalRecoveredCarrierServiceEvidenceAdapter)->enrichActualShipVia($candidate, $row)
        );
    }

    #[Test]
    public function it_approves_east_outbound_invoice_with_exact_order_and_tracking(): void
    {
        $out = (new HistoricalRecoveredCarrierServiceEvidenceAdapter)->enrichEastOutboundInvoice(
            $this->candidate(),
            $this->eastRow('USPS-NJ(USPS-NJ)')
        );

        self::assertSame('USPS-NJ(USPS-NJ)', $out['evidence']['service']);
        self::assertTrue($out['evidence']['carrier_service_approved']);
        self::assertSame('Outbound Service', $out['evidence']['source_us_east_outbound_invoice']['service_source_field']);
        self::assertStringContainsString('us_east_outbound_invoice', $out['evidence_source']);
    }

    #[Test]
    public function it_rejects_east_order_reference_mismatch_or_upload_label(): void
    {
        $adapter = new HistoricalRecoveredCarrierServiceEvidenceAdapter;
        $candidate = $this->candidate();
        $row = $this->eastRow('USPS-NJ(USPS-NJ)');
        $row['order_reference'] = 'C99999';
        self::assertSame($candidate, $adapter->enrichEastOutboundInvoice($candidate, $row));

        $row = $this->eastRow('上传物流面单(Upload_Shipping_Label)');
        self::assertSame($candidate, $adapter->enrichEastOutboundInvoice($candidate, $row));
    }

    #[Test]
    public function it_fails_closed_on_existing_approved_service_conflict(): void
    {
        $candidate = $this->candidate();
        $candidate['evidence']['service'] = 'USPS Priority Mail';
        $candidate['evidence']['carrier_service_approved'] = true;
        $out = (new HistoricalRecoveredCarrierServiceEvidenceAdapter)->enrichActualShipVia(
            $candidate,
            $this->westRow('USPS Ground Advantage')
        );

        self::assertTrue($out['conflicts']['source_evidence_conflict']);
        self::assertSame('USPS Priority Mail', $out['evidence']['service']);
    }

    #[Test]
    public function enrichment_is_idempotent(): void
    {
        $adapter = new HistoricalRecoveredCarrierServiceEvidenceAdapter;
        $row = $this->westRow('USPS Ground Advantage');
        $once = $adapter->enrichActualShipVia($this->candidate(), $row);
        $twice = $adapter->enrichActualShipVia($once, $row);

        self::assertSame($once, $twice);
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return [
            'candidate_key' => 'woocommerce:40000:9235990412345678901234',
            'identity' => ['exact' => true],
            'evidence_source' => 'woocommerce_tracking_meta+tencent_fulfillment_export',
            'evidence' => [
                'tracking_number' => '9235990412345678901234',
                'carrier' => 'USPS',
                'service' => null,
                'carrier_service_approved' => false,
            ],
            'conflicts' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function westRow(string $service): array
    {
        return [
            'evidence_kind' => 'actual_ship_via',
            'tracking_number' => '9235990412345678901234',
            'carrier' => 'USPS',
            'service' => $service,
            'source_count' => 1,
            'projection_sha256' => str_repeat('c', 64),
            'evidence_rows' => [[
                'invoice_sha256' => str_repeat('a', 64),
                'row_sha256' => str_repeat('b', 64),
                'invoice_name' => 'IN0201.xlsx',
                'row_number' => 149,
                'order_reference' => 'C40000',
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function eastRow(string $service): array
    {
        return [
            'evidence_kind' => 'east_outbound_invoice',
            'woo_id' => '40000',
            'order_reference' => 'C40000',
            'tracking_number' => '9235990412345678901234',
            'carrier' => 'USPS',
            'service' => $service,
            'source_count' => 1,
            'projection_sha256' => str_repeat('d', 64),
            'evidence_rows' => [[
                'source_file_sha256' => str_repeat('e', 64),
                'row_sha256' => str_repeat('f', 64),
                'source_file' => 'US-NY.xlsx',
                'row_number' => 26,
                'order_reference' => 'C40000',
            ]],
        ];
    }
}

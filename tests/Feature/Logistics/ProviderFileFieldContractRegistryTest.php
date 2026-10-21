<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\OverseaFiles\CanadaOutboundExporter;
use App\Modules\Logistics\Application\OverseaFiles\MorePlusOutboundExporter;
use App\Modules\Logistics\Application\OverseaFiles\Wms64OutboundExporter;
use ReflectionClass;
use Tests\TestCase;

final class ProviderFileFieldContractRegistryTest extends TestCase
{
    private array $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('resources/logistics/provider-contracts/provider-field-contracts.v1.json');
        $this->registry = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_registry_has_no_unregistered_or_anonymous_fields(): void
    {
        $this->assertTrue($this->registry['policy']['no_silent_blank']);
        $allowed = ['owner_review', 'confirmed', 'rejected', 'n/a', 'blocked_need_real_sample'];

        foreach ($this->registry['templates'] as $template) {
            $this->assertNotEmpty($template['key']);
            $this->assertNotEmpty($template['source_template']);
            $this->assertContains($template['owner_status'], $allowed);
            $ordinals = [];
            foreach ($template['fields'] as $field) {
                $this->assertNotEmpty($field['header']);
                $this->assertNotEmpty($field['semantic_role']);
                $this->assertNotEmpty($field['current_code']);
                $this->assertContains($field['owner_status'], $allowed);
                $ordinals[] = $field['ordinal'];
            }
            $expected = $ordinals === [] ? [] : range(1, count($ordinals));
            $this->assertSame($expected, $ordinals);
        }
    }

    public function test_confirmed_contracts_have_no_silent_or_ignored_fields(): void
    {
        foreach ($this->registry['templates'] as $template) {
            if ($template['owner_status'] !== 'confirmed') {
                continue;
            }
            foreach ($template['fields'] as $field) {
                $this->assertStringNotContainsString('silent blank', strtolower((string) $field['current_code']), $template['key'].' #'.$field['ordinal']);
                $this->assertStringNotContainsString('ignored by reader', strtolower((string) $field['current_code']), $template['key'].' #'.$field['ordinal']);
            }
        }
    }

    public function test_outbound_runtime_headers_match_contract_exactly(): void
    {
        $this->assertSame($this->headers(MorePlusOutboundExporter::class), $this->contractHeaders('01_MOREPLUS_OUT'));
        $wms = $this->headers(Wms64OutboundExporter::class);
        $this->assertSame($wms, $this->contractHeaders('03_USNY_OUT'));
        $this->assertSame($wms, $this->contractHeaders('05_USSM_OUT'));
        $this->assertSame($wms, $this->contractHeaders('07_DE_OUT'));
        $this->assertSame($this->headers(CanadaOutboundExporter::class), $this->contractHeaders('09_CA_OUT'));
    }

    public function test_known_return_boundaries_are_explicit(): void
    {
        $moreplus = $this->contractHeaders('02_MOREPLUS_RETURN');
        foreach (['Outbound Order#', 'Order#', 'Shipped Time', 'Master Tracking#', 'Tracking#', 'Ship Via', 'Carrier'] as $header) {
            $this->assertContains($header, $moreplus);
        }
        $variants = collect($this->template('02_MOREPLUS_RETURN')['accepted_variants'] ?? [])->keyBy('id');
        $this->assertSame('02_MOREPLUS_RETURN', $variants['standard_return_49']['headers_source'] ?? null);
        $this->assertSame('01_MOREPLUS_OUT', $variants['outbound_roundtrip_23']['headers_source'] ?? null);
        $this->assertSame('Order NO', $variants['outbound_roundtrip_23']['match_key'] ?? null);
        $this->assertSame(['Tracking Number'], $variants['outbound_roundtrip_23']['tracking_fields'] ?? null);

        foreach (['04_USNY_RETURN', '08_DE_RETURN'] as $key) {
            $headers = $this->contractHeaders($key);
            foreach (['Reference order No./参考单号', 'Tracking No./物流跟踪号', 'Carrier/承运商', 'Shipping service/物流渠道', 'Status/状态', 'OutboundTime/出库时间'] as $header) {
                $this->assertContains($header, $headers);
            }
        }

        $this->assertSame('blocked_need_real_sample', $this->template('06_USSM_RETURN')['owner_status']);
        $this->assertSame('confirmed', $this->template('10_CA_RETURN')['owner_status']);
        $this->assertSame(
            ['订单号', '包裹序号', 'com_lab_3015', '长(cm)', '宽(cm)', '高(cm)', '重量(kg)'],
            $this->contractHeaders('10_CA_RETURN')
        );
    }

    private function headers(string $class): array
    {
        return (new ReflectionClass($class))->getReflectionConstant('HEADERS')->getValue();
    }

    private function contractHeaders(string $key): array
    {
        return array_column($this->template($key)['fields'], 'header');
    }

    private function template(string $key): array
    {
        foreach ($this->registry['templates'] as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }
        $this->fail("Missing provider field contract: {$key}");
    }
}

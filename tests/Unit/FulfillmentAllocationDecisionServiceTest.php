<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\FulfillmentAllocationDecisionService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class FulfillmentAllocationDecisionServiceTest extends TestCase
{
    public function test_complete_costs_produce_advisory_system_recommendation(): void
    {
        config(['warehouse_routing.global.split_penalty_cny' => null]);
        $decision = app(FulfillmentAllocationDecisionService::class)->present($this->order(), [
            'status' => 'preview',
            'reason_code' => 'fulfillment_plans_ready',
            'comparison' => ['status' => 'ready'],
            'plans' => [
                $this->plan('factory-1', 'factory', false, 90, 'FACTORY'),
                $this->plan('de-1', 'single', false, 72, 'DE'),
            ],
        ]);

        self::assertSame('system_recommendation', $decision['status']);
        self::assertSame('de-1', $decision['recommended_plan_key']);
        self::assertSame(72.0, $decision['estimated_cost']);
        self::assertSame(18.0, $decision['estimated_saving']);
        self::assertTrue($decision['policy']['system_is_advisory']);
        self::assertTrue($decision['policy']['human_confirmation_required']);
    }

    public function test_split_candidate_without_frozen_penalty_falls_back_to_human_review(): void
    {
        config(['warehouse_routing.global.split_penalty_cny' => null]);
        $decision = app(FulfillmentAllocationDecisionService::class)->present($this->order(true), [
            'status' => 'preview',
            'reason_code' => 'fulfillment_plans_ready',
            'comparison' => ['status' => 'ready'],
            'plans' => [
                $this->plan('factory-1', 'factory', false, 90, 'FACTORY'),
                $this->splitPlan('split-1', 70),
            ],
        ]);

        self::assertSame('manual_review', $decision['status']);
        self::assertFalse($decision['can_use_recommendation']);
        self::assertContains('拆单附加成本尚未配置', $decision['manual_reasons']);
    }

    public function test_split_penalty_is_included_before_comparing_with_unsplit_plan(): void
    {
        config(['warehouse_routing.global.split_penalty_cny' => 25]);
        $decision = app(FulfillmentAllocationDecisionService::class)->present($this->order(true), [
            'status' => 'preview',
            'reason_code' => 'fulfillment_plans_ready',
            'comparison' => ['status' => 'ready'],
            'plans' => [
                $this->plan('factory-1', 'factory', false, 90, 'FACTORY'),
                $this->splitPlan('split-1', 70),
            ],
        ]);

        self::assertSame('system_recommendation', $decision['status']);
        self::assertSame('factory-1', $decision['recommended_plan_key']);
        $split = collect($decision['plans'])->firstWhere('plan_key', 'split-1');
        self::assertSame(95.0, $split['effective_cost']);
    }

    public function test_partial_or_missing_cost_evidence_never_guesses_a_recommendation(): void
    {
        config(['warehouse_routing.global.split_penalty_cny' => 10]);
        $decision = app(FulfillmentAllocationDecisionService::class)->present($this->order(), [
            'status' => 'preview',
            'reason_code' => 'fulfillment_plans_ready',
            'comparison' => ['status' => 'partial_evidence'],
            'plans' => [
                $this->plan('factory-1', 'factory', false, 90, 'FACTORY'),
                [
                    ...$this->plan('de-1', 'single', false, 72, 'DE'),
                    'cost' => ['status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'reason_code' => 'missing_packaging_cost'],
                ],
            ],
        ]);

        self::assertSame('manual_review', $decision['status']);
        self::assertContains('产品包装成本数据不足', $decision['manual_reasons']);
        self::assertContains('部分候选方案成本数据不足', $decision['manual_reasons']);
    }

    public function test_blocked_east_zone_does_not_claim_all_warehouses_are_out_of_stock(): void
    {
        $decision = app(FulfillmentAllocationDecisionService::class)->present($this->order(), [
            'status' => 'blocked',
            'reason_code' => 'insufficient_stock',
            'comparison' => ['status' => 'not_evaluated_missing_weight'],
            'fallback_policy' => ['mode' => 'inventory_only_missing_weight'],
            'plans' => [],
        ]);

        self::assertSame('manual_review', $decision['status']);
        self::assertNull($decision['recommended_plan']);
        self::assertFalse($decision['can_use_recommendation']);
        self::assertContains('当前区域路由与库存验收条件下无法形成自动候选方案；请参考库存列表核实人工备选仓', $decision['manual_reasons']);
    }

    private function order(bool $twoItems = false): Order
    {
        $order = new Order();
        $items = [new OrderItem(['product_id' => 10, 'sku' => 'SKU-A', 'product_summary' => 'A', 'quantity' => 1])];
        if ($twoItems) $items[] = new OrderItem(['product_id' => 20, 'sku' => 'SKU-B', 'product_summary' => 'B', 'quantity' => 1]);
        $order->setRelation('items', new Collection($items));
        return $order;
    }

    private function plan(string $key, string $type, bool $split, float $cost, string $source): array
    {
        return [
            'plan_key' => $key,
            'plan_type' => $type,
            'is_split' => $split,
            'shipment_count' => 1,
            'segments' => [[
                'source_type' => $source === 'FACTORY' ? 'factory' : 'warehouse',
                'source_code' => $source,
                'warehouse_id' => $source === 'FACTORY' ? null : 3,
                'warehouse_name' => $source === 'FACTORY' ? '中国工厂' : '德国天运',
                'items' => [['product_id' => 10, 'quantity' => 1]],
            ]],
            'cost' => ['status' => 'ready', 'amount' => $cost, 'currency' => 'CNY', 'source' => 'test'],
            'execution_status' => 'manual_review_only',
        ];
    }

    private function splitPlan(string $key, float $cost): array
    {
        return [
            'plan_key' => $key,
            'plan_type' => 'split',
            'is_split' => true,
            'shipment_count' => 2,
            'segments' => [
                ['source_type' => 'warehouse', 'source_code' => 'DE', 'warehouse_id' => 3, 'warehouse_name' => '德国天运', 'items' => [['product_id' => 10, 'quantity' => 1]]],
                ['source_type' => 'factory', 'source_code' => 'FACTORY', 'warehouse_id' => null, 'warehouse_name' => '中国工厂', 'items' => [['product_id' => 20, 'quantity' => 1]]],
            ],
            'cost' => ['status' => 'ready', 'amount' => $cost, 'currency' => 'CNY', 'source' => 'test'],
            'execution_status' => 'preview_only_multi_shipment_schema_required',
        ];
    }
}

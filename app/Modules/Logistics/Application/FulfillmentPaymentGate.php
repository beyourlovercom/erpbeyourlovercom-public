<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Application\PaymentAuthorityService;
use App\Modules\Orders\Application\PaymentAuthorityState;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;

final class FulfillmentPaymentGate
{
    public function __construct(private readonly PaymentAuthorityService $payments) {}

    /** @return array{allowed:bool,state:string,reason_code:string,reason_label:string} */
    public function evaluate(Model $order): array
    {
        $canonical = $order instanceof Order
            ? $order
            : Order::query()->whereKey($order->getKey())->firstOrFail();

        return $this->decision($this->payments->state($canonical));
    }

    /** @param iterable<Order> $orders @return array<int,array{allowed:bool,state:string,reason_code:string,reason_label:string}> */
    public function prepare(iterable $orders): array
    {
        return collect($this->payments->statesFor($orders))
            ->map(fn (PaymentAuthorityState $state): array => $this->decision($state))
            ->all();
    }

    public function assertAllowed(Model $order): void
    {
        $decision = $this->evaluate($order);
        abort_unless($decision['allowed'], 409, $decision['reason_label']);
    }

    /** @return array{allowed:bool,state:string,reason_code:string,reason_label:string} */
    private function decision(PaymentAuthorityState $state): array
    {
        return [
            'allowed' => $state->allowsFulfillment(),
            'state' => $state->value,
            'reason_code' => $this->reasonCode($state),
            'reason_label' => $this->reasonLabel($state),
        ];
    }

    private function reasonCode(PaymentAuthorityState $state): string
    {
        return match ($state) {
            PaymentAuthorityState::NotRequired => 'payment_not_required',
            PaymentAuthorityState::Paid => 'payment_paid',
            PaymentAuthorityState::Unconfirmed => 'payment_unconfirmed',
            PaymentAuthorityState::Pending => 'payment_pending',
            PaymentAuthorityState::Hold => 'payment_hold',
            PaymentAuthorityState::Failed => 'payment_failed',
            PaymentAuthorityState::Cancelled => 'payment_cancelled',
            PaymentAuthorityState::Refunded => 'payment_refunded',
        };
    }

    private function reasonLabel(PaymentAuthorityState $state): string
    {
        return match ($state) {
            PaymentAuthorityState::NotRequired => '该订单不要求 WooCommerce 付款门校验。',
            PaymentAuthorityState::Paid => '付款已确认。',
            PaymentAuthorityState::Unconfirmed => '订单缺少可验证的付款证据，已阻止履约。',
            PaymentAuthorityState::Pending => '订单尚未确认付款，已阻止履约。',
            PaymentAuthorityState::Hold => '订单当前处于支付挂起或争议状态，已阻止履约。',
            PaymentAuthorityState::Failed => '订单支付失败，已阻止履约。',
            PaymentAuthorityState::Cancelled => '订单已取消，已阻止履约。',
            PaymentAuthorityState::Refunded => '订单已退款，已阻止履约。',
        };
    }
}

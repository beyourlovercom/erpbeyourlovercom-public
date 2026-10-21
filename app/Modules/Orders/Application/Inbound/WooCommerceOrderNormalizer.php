<?php

namespace App\Modules\Orders\Application\Inbound;

use App\Modules\Orders\Application\OrderStatus;
use InvalidArgumentException;

final class WooCommerceOrderNormalizer
{
    public function normalize(array $payload, string $connectionKey): NormalizedInboundOrder
    {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('WooCommerce order id is missing.');
        }

        $items = $this->items($payload['line_items'] ?? []);
        if ($items === []) {
            throw new InvalidArgumentException("WooCommerce order {$id} has no line items.");
        }

        $date = (string) ($payload['date_created_gmt'] ?? $payload['date_created'] ?? '');
        $modified = (string) ($payload['date_modified_gmt'] ?? $payload['date_modified'] ?? $date);
        if ($date === '') {
            throw new InvalidArgumentException("WooCommerce order {$id} has no creation date.");
        }

        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'USD')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'USD';
        }

        return new NormalizedInboundOrder(
            provider: 'woocommerce',
            connectionKey: $connectionKey,
            reference: (string) $id,
            externalOrderNumber: trim((string) ($payload['number'] ?? $id)),
            orderDate: substr($date, 0, 10),
            providerModifiedAt: $modified,
            currency: $currency,
            orderStatus: $this->status((string) ($payload['status'] ?? 'pending')),
            orderNote: $this->nullable((string) ($payload['customer_note'] ?? '')),
            address: $this->address($payload),
            items: $items,
            settlementEvidence: $this->settlementEvidence($payload, $currency, (string) $id, $modified),
        );
    }

    /** @return array<string,mixed> */
    private function settlementEvidence(array $payload, string $currency, string $reference, string $modified): array
    {
        $listAmountCents = 0;
        $hasListAmount = true;
        foreach (($payload['line_items'] ?? []) as $line) {
            if (! is_array($line) || ! array_key_exists('subtotal', $line) || $line['subtotal'] === '') {
                $hasListAmount = false;
                break;
            }
            $listAmountCents += $this->moneyCents($line['subtotal']);
        }

        $discountCents = $this->optionalMoneyCents($payload, 'discount_total');
        $shippingCents = $this->optionalMoneyCents($payload, 'shipping_total');
        $taxCents = $this->optionalMoneyCents($payload, 'total_tax');
        $providerTotalCents = $this->optionalMoneyCents($payload, 'total');

        $refundAmountCents = null;
        $refundTaxCents = null;
        if (array_key_exists('refunds', $payload) && is_array($payload['refunds'])) {
            $refundAmountCents = 0;
            $refundTaxCents = 0;
            foreach ($payload['refunds'] as $refund) {
                if (! is_array($refund)) {
                    $refundAmountCents = null;
                    $refundTaxCents = null;
                    break;
                }
                if ($refundAmountCents !== null) {
                    $refundTotal = array_key_exists('total', $refund) ? trim((string) $refund['total']) : '';
                    if ($refundTotal === '' || $refundTotal === '-') {
                        $refundAmountCents = null;
                    } else {
                        $refundAmountCents += abs($this->moneyCents($refundTotal));
                    }
                }
                if ($refundTaxCents !== null) {
                    $refundTax = array_key_exists('total_tax', $refund) ? trim((string) $refund['total_tax']) : '';
                    if ($refundTax === '' || $refundTax === '-') {
                        $refundTaxCents = null;
                    } else {
                        $refundTaxCents += abs($this->moneyCents($refundTax));
                    }
                }
            }
        }

        $canReconcile = $hasListAmount
            && $discountCents !== null
            && $shippingCents !== null
            && $taxCents !== null
            && $providerTotalCents !== null;
        $reconciliationException = null;
        $reconciliationStatus = 'incomplete';
        if ($canReconcile) {
            $expectedProviderTotalCents = $listAmountCents - $discountCents + $shippingCents + $taxCents;
            $differenceCents = $expectedProviderTotalCents - $providerTotalCents;
            $reconciliationException = abs($differenceCents) > 1 ? [
                'expected_provider_total' => $this->formatCents($expectedProviderTotalCents),
                'provider_total' => $this->formatCents($providerTotalCents),
                'difference' => $this->formatCents($differenceCents),
            ] : null;
            $reconciliationStatus = $reconciliationException === null ? 'matched' : 'exception';
        }

        $actualFee = $this->actualPaymentFeeEvidence($payload);

        return [
            'provider' => 'woocommerce',
            'provider_reference' => $reference,
            'provider_modified_at' => $modified,
            'provider_order_status' => strtolower(trim((string) ($payload['status'] ?? 'pending'))),
            'currency' => $currency,
            'merchandise_list_amount' => $hasListAmount ? $this->formatCents($listAmountCents) : null,
            'discount_amount' => $this->formatNullableCents($discountCents),
            'shipping_revenue' => $this->formatNullableCents($shippingCents),
            'tax_collected' => $this->formatNullableCents($taxCents),
            'provider_order_total' => $this->formatNullableCents($providerTotalCents),
            'refund_amount' => $this->formatNullableCents($refundAmountCents),
            'refund_tax' => $this->formatNullableCents($refundTaxCents),
            'payment_method' => $this->nullable((string) ($payload['payment_method'] ?? '')),
            'payment_transaction_id' => $this->nullable((string) ($payload['transaction_id'] ?? '')),
            'actual_payment_fee' => $actualFee['amount'],
            'actual_payment_fee_source' => $actualFee['source'],
            'actual_payment_fee_status' => $actualFee['status'],
            'paid_at' => $this->nullable((string) ($payload['date_paid_gmt'] ?? $payload['date_paid'] ?? '')),
            'reconciliation_status' => $reconciliationStatus,
            'reconciliation_exception' => $reconciliationException,
        ];
    }

    /** @return array{amount:?string,source:?string,status:string} */
    private function actualPaymentFeeEvidence(array $payload): array
    {
        $method = strtolower(trim((string) ($payload['payment_method'] ?? '')));
        $source = match (true) {
            str_starts_with($method, 'woocommerce_payments') => '_wcpay_transaction_fee',
            $method === 'cpsw_stripe_element' => '_cpsw_stripe_fee',
            in_array($method, ['ppcp', 'ppcp_card'], true) => '_paypal_fee',
            default => null,
        };

        if ($source === null) {
            return ['amount' => null, 'source' => null, 'status' => 'unavailable'];
        }

        $meta = $payload['meta_data'] ?? [];
        if (! is_array($meta)) {
            return ['amount' => null, 'source' => $source, 'status' => 'missing'];
        }

        $seen = false;
        $values = [];
        foreach ($meta as $row) {
            if (! is_array($row) || (string) ($row['key'] ?? '') !== $source) {
                continue;
            }

            $seen = true;
            $value = $row['value'] ?? null;
            if (! is_scalar($value)) {
                return ['amount' => null, 'source' => $source, 'status' => 'invalid'];
            }

            try {
                $cents = $this->moneyCents($value);
            } catch (InvalidArgumentException) {
                return ['amount' => null, 'source' => $source, 'status' => 'invalid'];
            }

            if ($cents < 0) {
                return ['amount' => null, 'source' => $source, 'status' => 'invalid'];
            }

            $values[$this->formatCents($cents)] = true;
        }

        if (! $seen) {
            return ['amount' => null, 'source' => $source, 'status' => 'missing'];
        }

        if (count($values) !== 1) {
            return ['amount' => null, 'source' => $source, 'status' => 'conflict'];
        }

        return [
            'amount' => (string) array_key_first($values),
            'source' => $source,
            'status' => 'observed',
        ];
    }

    private function optionalMoneyCents(array $payload, string $key): ?int
    {
        if (! array_key_exists($key, $payload) || $payload[$key] === '') {
            return null;
        }

        return $this->moneyCents($payload[$key]);
    }

    private function formatNullableCents(?int $cents): ?string
    {
        return $cents === null ? null : $this->formatCents($cents);
    }

    private function moneyCents(mixed $value): int
    {
        $value = trim((string) $value);
        if (! preg_match('/^(?<sign>-)?(?<whole>\\d{1,16})(?:\\.(?<fraction>\\d{1,6}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException('WooCommerce money value is not a supported decimal.');
        }
        $fraction = str_pad($matches['fraction'] ?? '', 3, '0');
        $cents = ((int) $matches['whole'] * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return ($matches['sign'] ?? '') === '-' ? -$cents : $cents;
    }

    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function status(string $status): string
    {
        return match (strtolower($status)) {
            'completed' => OrderStatus::Shipped->value,
            'cancelled', 'refunded', 'failed', 'trash' => OrderStatus::Cancelled->value,
            default => OrderStatus::PendingShipment->value,
        };
    }

    /** @return array<string,string> */
    private function address(array $payload): array
    {
        $shipping = is_array($payload['shipping'] ?? null) ? $payload['shipping'] : [];
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $source = $this->hasAddress($shipping) ? $shipping : $billing;
        $name = trim(((string) ($source['first_name'] ?? '')).' '.((string) ($source['last_name'] ?? '')));
        if ($name === '') {
            $name = trim((string) ($source['company'] ?? '')) ?: 'WooCommerce customer';
        }

        $shippingPhone = trim((string) ($shipping['phone'] ?? ''));
        $billingPhone = trim((string) ($billing['phone'] ?? ''));
        $shippingEmail = trim((string) ($shipping['email'] ?? ''));
        $billingEmail = trim((string) ($billing['email'] ?? ''));
        $shippingCompany = trim((string) ($shipping['company'] ?? ''));
        $billingCompany = trim((string) ($billing['company'] ?? ''));

        return [
            'recipient_name' => $name,
            'phone' => $shippingPhone !== '' ? $shippingPhone : $billingPhone,
            'email' => $shippingEmail !== '' ? $shippingEmail : $billingEmail,
            'company' => $shippingCompany !== '' ? $shippingCompany : $billingCompany,
            'country' => strtoupper(trim((string) ($source['country'] ?? ''))),
            'province_state' => trim((string) ($source['state'] ?? '')),
            'city' => trim((string) ($source['city'] ?? '')),
            'address_line_1' => trim((string) ($source['address_1'] ?? '')),
            'address_line_2' => trim((string) ($source['address_2'] ?? '')),
            'postal_code' => trim((string) ($source['postcode'] ?? '')),
        ];
    }

    private function hasAddress(array $address): bool
    {
        foreach (['address_1', 'city', 'postcode', 'country', 'first_name', 'last_name'] as $key) {
            if (trim((string) ($address[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string,mixed>> */
    private function items(mixed $lineItems): array
    {
        if (! is_array($lineItems)) {
            return [];
        }

        $result = [];
        foreach ($lineItems as $line) {
            if (! is_array($line)) {
                continue;
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $lineItemId = (int) ($line['id'] ?? 0);
            $variationId = (int) ($line['variation_id'] ?? 0);
            $productId = (int) ($line['product_id'] ?? 0);
            $remoteProductId = $variationId > 0 ? $variationId : $productId;
            $referenceType = $variationId > 0 ? 'variation' : 'product';
            $price = trim((string) ($line['price'] ?? ''));
            if ($price === '') {
                $price = number_format(((float) ($line['total'] ?? 0)) / $quantity, 2, '.', '');
            }
            $result[] = [
                'woocommerce_line_item_id' => $lineItemId > 0 ? $lineItemId : null,
                'woocommerce_product_id' => $remoteProductId > 0 ? $remoteProductId : null,
                'woocommerce_reference_type' => $referenceType,
                'sku' => trim((string) ($line['sku'] ?? '')),
                'product_summary' => trim((string) ($line['name'] ?? '')) ?: 'WooCommerce item',
                'quantity' => $quantity,
                'unit_price' => number_format((float) $price, 2, '.', ''),
            ];
        }

        return $result;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

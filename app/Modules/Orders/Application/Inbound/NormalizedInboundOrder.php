<?php

namespace App\Modules\Orders\Application\Inbound;

final readonly class NormalizedInboundOrder
{
    /** @param array<string,string> $address @param list<array<string,mixed>> $items @param array<string,mixed>|null $settlementEvidence */
    public function __construct(
        public string $provider,
        public string $connectionKey,
        public string $reference,
        public string $externalOrderNumber,
        public string $orderDate,
        public string $providerModifiedAt,
        public string $currency,
        public string $orderStatus,
        public ?string $orderNote,
        public array $address,
        public array $items,
        public ?array $settlementEvidence = null,
    ) {}
}

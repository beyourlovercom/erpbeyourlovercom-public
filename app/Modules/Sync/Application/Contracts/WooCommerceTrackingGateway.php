<?php

namespace App\Modules\Sync\Application\Contracts;

interface WooCommerceTrackingGateway
{
    /** @return array{configured:bool,url:string,connection_key:string} */
    public function descriptor(): array;

    /** @return array{tracking_number:string,provider:string,ship_date?:?string,has_tracking:bool} */
    public function inspectTracking(int $providerOrderId): array;

    /**
     * @return array{written:bool,verified:bool}
     */
    public function syncTracking(
        int $providerOrderId,
        string $providerSlug,
        string $trackingNumber,
        string $shipDate,
        array $lineItems = [],
    ): array;

    /** @return array{written:bool,verified:bool,status:string} */
    public function syncOrderStatus(int $providerOrderId, string $status): array;
}

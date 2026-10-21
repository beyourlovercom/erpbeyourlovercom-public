<?php

namespace App\Modules\Logistics\Application\Contracts;

interface DeliveryEvidenceGateway
{
    /** @return array{configured:bool,source:string,version:string} */
    public function descriptor(): array;

    /**
     * @return array{
     *   found:bool,tracking_number:string,slug:string,provider_reference:?string,
     *   tag:?string,subtag:?string,subtag_message:?string,provider_updated_at:?string,
     *   checkpoint:?array<string,mixed>
     * }
     */
    public function inspect(string $slug, string $trackingNumber): array;
}

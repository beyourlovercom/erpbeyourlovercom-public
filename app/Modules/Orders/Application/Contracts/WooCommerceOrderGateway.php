<?php

namespace App\Modules\Orders\Application\Contracts;

interface WooCommerceOrderGateway
{
    /** @return array{configured:bool,url:string,connection_key:string} */
    public function descriptor(): array;

    /** @return array{ok:bool,status:int} */
    public function probe(): array;

    /** @return array<string,mixed> */
    public function fetchOrder(int $providerOrderId): array;

    /** @return array{items:list<array<string,mixed>>,total_pages:int} */
    public function fetchOrders(array $query): array;
}

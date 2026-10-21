<?php

namespace App\Modules\Products\Application\Contracts;

interface WooCommerceProductGateway
{
    /** @return array{configured:bool,url:string,connection_key:string} */
    public function descriptor(): array;

    /** @return array{ok:bool,status:int} */
    public function probe(): array;

    /** @return array<string,mixed> */
    public function fetchProduct(int $remoteProductId): array;

    /** @return list<array<string,mixed>> */
    public function products(int $page = 1, int $perPage = 100, ?string $modifiedAfter = null): array;
}

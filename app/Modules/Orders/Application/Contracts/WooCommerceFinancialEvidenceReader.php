<?php

namespace App\Modules\Orders\Application\Contracts;

interface WooCommerceFinancialEvidenceReader
{
    /** @return array{configured:bool,url:string,connection_key:string} */
    public function descriptor(): array;

    /** @return array<string,mixed> */
    public function fetchOne(int $providerOrderId): array;

    /** @return array{items:list<array<string,mixed>>,total_pages:int} */
    public function fetchPage(array $query): array;
}

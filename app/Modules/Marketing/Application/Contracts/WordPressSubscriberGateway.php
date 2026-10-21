<?php

namespace App\Modules\Marketing\Application\Contracts;

interface WordPressSubscriberGateway
{
    /** @return array{configured:bool,url:string,connection_key:string} */
    public function descriptor(): array;

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string,source_site:?string,request_id:string} */
    public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array;
}

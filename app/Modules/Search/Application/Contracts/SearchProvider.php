<?php

namespace App\Modules\Search\Application\Contracts;

interface SearchProvider
{
    public function key(): string;

    public function label(): string;

    public function permission(): string;

    /** @return list<array{id:string,label:string,subtitle:string,href:string,meta:array<string,string|int|null>}> */
    public function search(string $query, int $limit): array;

    public function viewAllHref(string $query): string;
}

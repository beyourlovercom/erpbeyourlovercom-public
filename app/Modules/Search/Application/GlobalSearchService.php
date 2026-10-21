<?php

namespace App\Modules\Search\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Search\Application\Contracts\SearchProvider;

final class GlobalSearchService
{
    /** @param list<SearchProvider> $providers */
    public function __construct(private readonly array $providers) {}

    /** @return array{query:string,min_query_length:int,groups:list<array{key:string,label:string,view_all_href:string,items:array}>} */
    public function search(User $user, string $query, int $limitPerProvider = 5): array
    {
        $query = trim($query);
        $limit = max(1, min($limitPerProvider, 10));

        if (mb_strlen($query) < 2) {
            return ['query' => $query, 'min_query_length' => 2, 'groups' => []];
        }

        $groups = [];
        foreach ($this->providers as $provider) {
            if (! $user->can($provider->permission())) {
                continue;
            }

            $items = $provider->search($query, $limit);
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'view_all_href' => $provider->viewAllHref($query),
                'items' => $items,
            ];
        }

        return ['query' => $query, 'min_query_length' => 2, 'groups' => $groups];
    }
}

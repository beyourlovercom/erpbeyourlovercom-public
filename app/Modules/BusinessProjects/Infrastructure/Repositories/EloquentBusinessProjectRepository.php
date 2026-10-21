<?php

namespace App\Modules\BusinessProjects\Infrastructure\Repositories;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProject;
use App\Modules\BusinessProjects\Infrastructure\Models\BusinessProjectSite;
use Illuminate\Support\Collection;

final class EloquentBusinessProjectRepository implements BusinessProjectRepository
{
    public function findByKey(string $projectKey): ?object
    {
        return BusinessProject::where('project_key', $projectKey)->first();
    }

    /** @return Collection<int, object> */
    public function listSites(int $projectId): Collection
    {
        return BusinessProjectSite::where('business_project_id', $projectId)
            ->where('status', 'active')
            ->orderBy('is_primary', 'desc')
            ->orderBy('id')
            ->get();
    }

    public function normalizeDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        return strtolower(rtrim((string) $host, '.'));
    }
}

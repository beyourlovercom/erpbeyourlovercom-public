<?php

namespace App\Modules\BusinessProjects\Application;

use App\Modules\BusinessProjects\Application\Contracts\BusinessProjectRepository;
use Illuminate\Support\Collection;

final class BusinessProjectService
{
    public function __construct(private readonly BusinessProjectRepository $repository) {}

    public function findByKey(string $projectKey): ?object
    {
        return $this->repository->findByKey($projectKey);
    }

    /** @return Collection<int, object> */
    public function listSites(int $projectId): Collection
    {
        return $this->repository->listSites($projectId);
    }

    public function normalizeDomain(string $url): string
    {
        return $this->repository->normalizeDomain($url);
    }
}

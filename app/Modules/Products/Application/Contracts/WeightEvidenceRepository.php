<?php

namespace App\Modules\Products\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface WeightEvidenceRepository
{
    public function create(array $attributes): Model;

    public function findById(int $id): ?Model;

    public function update(Model $evidence, array $attributes): void;

    public function paginateFiltered(array $filters, int $perPage): LengthAwarePaginator;

    public function pendingCount(): int;
}

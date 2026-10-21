<?php

namespace App\Modules\Finance\Application\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface ConfirmedLossReader
{
    public function applyConfirmedLossScope(Builder $query): Builder;

    public function isConfirmedLoss(Model $order): bool;
}

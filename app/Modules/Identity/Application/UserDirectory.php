<?php

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\DB;

final class UserDirectory
{
    /** @param iterable<int|string> $ids @return array<string, string> */
    public function namesForIds(iterable $ids): array
    {
        $normalized = collect($ids)->filter(fn ($id): bool => $id !== null && (string) $id !== '')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($normalized->isEmpty()) return [];

        return DB::table('users')->whereIn('id', $normalized)->pluck('name', 'id')->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])->all();
    }
}

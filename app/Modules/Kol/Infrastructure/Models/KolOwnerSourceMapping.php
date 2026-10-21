<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolOwnerSourceMapping extends Model
{
    protected $fillable = ['source_owner_key', 'source_owner_value', 'mapped_user_id', 'created_by', 'updated_by'];

    public function mappedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_user_id');
    }
}

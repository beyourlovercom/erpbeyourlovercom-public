<?php

namespace App\Modules\Customers\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    protected $fillable = ['name', 'description'];

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerGroupMembership::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_group_memberships')
            ->withPivot(['added_by'])
            ->withTimestamps();
    }
}

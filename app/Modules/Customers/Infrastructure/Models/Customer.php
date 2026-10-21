<?php

namespace App\Modules\Customers\Infrastructure\Models;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'country_code', 'language_code',
        'source_type', 'owner_id', 'notes', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo('App\\Modules\\Identity\\Infrastructure\\Models\\User', 'owner_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function billingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('address_type', 'billing');
    }

    public function shippingAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('address_type', 'shipping');
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(CustomerGroupMembership::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'customer_group_memberships')
            ->withPivot(['added_by'])
            ->withTimestamps();
    }

    public function getCustomerNumberAttribute(): string
    {
        return 'CUS-'.str_pad((string) $this->getKey(), 8, '0', STR_PAD_LEFT);
    }
}

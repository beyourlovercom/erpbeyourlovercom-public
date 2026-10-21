<?php

namespace App\Modules\Marketing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MarketingSubscriber extends Model
{
    protected $table = 'marketing_subscribers';

    protected $fillable = ['email', 'first_name', 'last_name', 'phone', 'country_code', 'language_code'];

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MarketingSubscription::class, 'subscriber_id');
    }
}

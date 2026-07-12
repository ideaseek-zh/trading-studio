<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscriber_key',
        'subscriber_name',
        'channel_type',
        'priority_level',
        'priority_order',
        'endpoint_url',
        'secret_token',
        'min_signal_score',
        'enabled',
        'filters',
        'last_notified_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'filters' => 'array',
        'last_notified_at' => 'datetime',
        'min_signal_score' => 'decimal:2',
        'priority_order' => 'integer',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(SignalDelivery::class, 'signal_subscription_id');
    }
}

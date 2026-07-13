<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationChannelCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'credential_key',
        'name',
        'channel_type',
        'endpoint_url',
        'secret_token',
        'signing_secret',
        'config',
        'enabled',
        'last_verified_at',
    ];

    protected $casts = [
        'endpoint_url' => 'encrypted',
        'secret_token' => 'encrypted',
        'signing_secret' => 'encrypted',
        'config' => 'array',
        'enabled' => 'boolean',
        'last_verified_at' => 'datetime',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SignalSubscription::class, 'notification_channel_credential_id');
    }
}

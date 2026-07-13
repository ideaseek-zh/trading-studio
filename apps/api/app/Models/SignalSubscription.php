<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'notification_template_id',
        'notification_channel_credential_id',
        'channel_routes',
        'min_signal_score',
        'enabled',
        'filters',
        'quiet_hours',
        'escalation_rules',
        'debounce_window_minutes',
        'merge_window_minutes',
        'max_merge_signals',
        'last_notified_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'filters' => 'array',
        'channel_routes' => 'array',
        'quiet_hours' => 'array',
        'escalation_rules' => 'array',
        'last_notified_at' => 'datetime',
        'min_signal_score' => 'decimal:2',
        'priority_order' => 'integer',
        'notification_template_id' => 'integer',
        'notification_channel_credential_id' => 'integer',
        'debounce_window_minutes' => 'integer',
        'merge_window_minutes' => 'integer',
        'max_merge_signals' => 'integer',
    ];

    public function notificationTemplate(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function notificationChannelCredential(): BelongsTo
    {
        return $this->belongsTo(NotificationChannelCredential::class, 'notification_channel_credential_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SignalDelivery::class, 'signal_subscription_id');
    }
}

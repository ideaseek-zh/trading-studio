<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_key',
        'name',
        'channel_type',
        'message_format',
        'subject_template',
        'body_template',
        'config',
        'enabled',
    ];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(SignalSubscription::class, 'notification_template_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyWorkspace extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_key',
        'name',
        'owner_key',
        'workspace_type',
        'risk_profile',
        'base_currency',
        'default_signal_subscription_id',
        'settings',
        'enabled',
        'last_reviewed_at',
    ];

    protected $casts = [
        'default_signal_subscription_id' => 'integer',
        'settings' => 'array',
        'enabled' => 'boolean',
        'last_reviewed_at' => 'datetime',
    ];

    public function defaultSignalSubscription(): BelongsTo
    {
        return $this->belongsTo(SignalSubscription::class, 'default_signal_subscription_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StrategyWorkspaceItem::class);
    }
}

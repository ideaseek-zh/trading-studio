<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'signal_key',
        'signal_rule_id',
        'event_chain_id',
        'latest_event_id',
        'primary_security_id',
        'signal_type',
        'direction',
        'horizon_label',
        'status',
        'title',
        'summary',
        'signal_score',
        'confidence_score',
        'urgency_score',
        'impact_score',
        'risk_score',
        'triggered_at',
        'published_at',
        'expires_at',
        'reasoning',
        'explanation',
        'performance_summary',
        'last_evaluated_at',
        'facts',
    ];

    protected $casts = [
        'signal_score' => 'decimal:2',
        'confidence_score' => 'decimal:2',
        'urgency_score' => 'decimal:2',
        'impact_score' => 'decimal:2',
        'risk_score' => 'decimal:2',
        'triggered_at' => 'datetime',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'reasoning' => 'array',
        'explanation' => 'array',
        'performance_summary' => 'array',
        'last_evaluated_at' => 'datetime',
        'facts' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(SignalRule::class, 'signal_rule_id');
    }

    public function eventChain(): BelongsTo
    {
        return $this->belongsTo(EventChain::class, 'event_chain_id');
    }

    public function latestEvent(): BelongsTo
    {
        return $this->belongsTo(MarketEvent::class, 'latest_event_id');
    }

    public function primarySecurity(): BelongsTo
    {
        return $this->belongsTo(Security::class, 'primary_security_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SignalDelivery::class, 'trading_signal_id');
    }

    public function performanceSnapshots(): HasMany
    {
        return $this->hasMany(SignalPerformanceSnapshot::class, 'trading_signal_id');
    }
}

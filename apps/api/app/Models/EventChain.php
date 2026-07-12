<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventChain extends Model
{
    use HasFactory;

    protected $fillable = [
        'chain_key',
        'chain_type',
        'topic',
        'summary',
        'status',
        'primary_security_id',
        'started_at',
        'latest_occurred_at',
        'latest_published_at',
        'importance_level',
        'sentiment',
        'event_count',
        'article_count',
        'facts',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'latest_occurred_at' => 'datetime',
        'latest_published_at' => 'datetime',
        'facts' => 'array',
    ];

    public function primarySecurity(): BelongsTo
    {
        return $this->belongsTo(Security::class, 'primary_security_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketEvent::class, 'event_chain_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(TradingSignal::class, 'event_chain_id');
    }
}

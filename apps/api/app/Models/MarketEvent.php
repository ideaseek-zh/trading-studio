<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketEvent extends Model
{
    use HasFactory;

    protected $table = 'events';

    protected $fillable = [
        'event_type',
        'title',
        'summary',
        'occurred_at',
        'detected_at',
        'importance_level',
        'sentiment',
        'confidence',
        'status',
        'primary_security_id',
        'event_chain_id',
        'timeline_stage',
        'timeline_order',
        'fingerprint',
        'facts',
        'ai_analysis',
        'published_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'detected_at' => 'datetime',
        'published_at' => 'datetime',
        'facts' => 'array',
        'ai_analysis' => 'array',
        'confidence' => 'decimal:4',
        'timeline_order' => 'integer',
    ];

    public function primarySecurity(): BelongsTo
    {
        return $this->belongsTo(Security::class, 'primary_security_id');
    }

    public function eventChain(): BelongsTo
    {
        return $this->belongsTo(EventChain::class, 'event_chain_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EventSource::class, 'event_id');
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(NewsArticle::class, 'event_sources', 'event_id', 'news_article_id')
            ->withPivot(['relation_type'])
            ->withTimestamps();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NewsArticle extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_id',
        'source_item_id',
        'title',
        'summary',
        'canonical_url',
        'author',
        'published_at',
        'fetched_at',
        'source_timestamp',
        'category',
        'importance_level',
        'sentiment',
        'status',
        'language',
        'copyright_status',
        'quality_status',
        'quality_score',
        'parser_version',
        'request_id',
        'checksum',
        'title_hash',
        'content_hash',
        'simhash',
        'cluster_id',
        'ai_processed_at',
        'metadata',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'fetched_at' => 'datetime',
        'source_timestamp' => 'datetime',
        'ai_processed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(NewsSource::class, 'source_id');
    }

    public function content(): HasOne
    {
        return $this->hasOne(NewsArticleContent::class, 'news_article_id');
    }

    public function articleSecurities(): HasMany
    {
        return $this->hasMany(NewsArticleSecurity::class, 'news_article_id');
    }

    public function securities(): BelongsToMany
    {
        return $this->belongsToMany(Security::class, 'news_article_securities', 'news_article_id', 'security_id')
            ->withPivot(['mention_type', 'matched_text', 'confidence', 'is_primary'])
            ->withTimestamps();
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(MarketEvent::class, 'event_sources', 'news_article_id', 'event_id')
            ->withPivot(['relation_type'])
            ->withTimestamps();
    }
}

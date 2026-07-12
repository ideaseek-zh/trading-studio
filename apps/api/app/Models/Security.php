<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Security extends Model
{
    use HasFactory;

    protected $fillable = [
        'canonical_symbol',
        'symbol',
        'exchange',
        'market',
        'security_type',
        'name',
        'short_name',
        'pinyin',
        'list_date',
        'delist_date',
        'status',
        'currency',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'list_date' => 'date',
        'delist_date' => 'date',
    ];

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(NewsArticle::class, 'news_article_securities', 'security_id', 'news_article_id')
            ->withPivot(['mention_type', 'matched_text', 'confidence', 'is_primary'])
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketEvent::class, 'primary_security_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(TradingSignal::class, 'primary_security_id');
    }
}

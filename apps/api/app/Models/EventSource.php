<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'news_article_id',
        'relation_type',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(MarketEvent::class, 'event_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(NewsArticle::class, 'news_article_id');
    }
}

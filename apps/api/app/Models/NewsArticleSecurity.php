<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsArticleSecurity extends Model
{
    use HasFactory;

    protected $fillable = [
        'news_article_id',
        'security_id',
        'mention_type',
        'matched_text',
        'confidence',
        'is_primary',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'is_primary' => 'boolean',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(NewsArticle::class, 'news_article_id');
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class, 'security_id');
    }
}

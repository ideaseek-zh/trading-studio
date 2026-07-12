<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsArticleContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'news_article_id',
        'content_text',
        'content_html',
        'attachments',
        'images',
        'tags',
        'raw_payload',
        'quality_issues',
        'structured_data',
        'extraction_version',
        'extracted_at',
        'cleaned_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'images' => 'array',
        'tags' => 'array',
        'raw_payload' => 'array',
        'quality_issues' => 'array',
        'structured_data' => 'array',
        'extracted_at' => 'datetime',
        'cleaned_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(NewsArticle::class, 'news_article_id');
    }
}

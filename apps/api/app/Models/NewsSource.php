<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_key',
        'source_name',
        'source_type',
        'provider',
        'access_mode',
        'base_url',
        'copyright_status',
        'robots_checked',
        'rate_limit_per_minute',
        'timeout_seconds',
        'retry_times',
        'enabled',
    ];

    protected $casts = [
        'robots_checked' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(NewsArticle::class, 'source_id');
    }
}

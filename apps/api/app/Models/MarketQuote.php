<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'security_id',
        'quote_time',
        'last_price',
        'pre_close',
        'open',
        'high',
        'low',
        'volume',
        'amount',
        'turnover_rate',
        'pct_change',
        'provider',
        'source_timestamp',
        'metadata',
    ];

    protected $casts = [
        'quote_time' => 'datetime',
        'source_timestamp' => 'datetime',
        'metadata' => 'array',
        'last_price' => 'decimal:4',
        'pre_close' => 'decimal:4',
        'open' => 'decimal:4',
        'high' => 'decimal:4',
        'low' => 'decimal:4',
        'volume' => 'decimal:4',
        'amount' => 'decimal:4',
        'turnover_rate' => 'decimal:6',
        'pct_change' => 'decimal:6',
    ];

    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class);
    }
}

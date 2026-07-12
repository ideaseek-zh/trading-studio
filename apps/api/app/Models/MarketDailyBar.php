<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketDailyBar extends Model
{
    use HasFactory;

    protected $fillable = [
        'security_id',
        'trade_date',
        'open',
        'high',
        'low',
        'close',
        'pre_close',
        'volume',
        'amount',
        'turnover_rate',
        'pct_change',
        'adjust_type',
        'provider',
        'source_timestamp',
        'metadata',
    ];

    protected $casts = [
        'trade_date' => 'date',
        'source_timestamp' => 'datetime',
        'metadata' => 'array',
        'open' => 'decimal:4',
        'high' => 'decimal:4',
        'low' => 'decimal:4',
        'close' => 'decimal:4',
        'pre_close' => 'decimal:4',
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

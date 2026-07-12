<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketIndex extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'exchange',
        'market',
        'index_type',
        'status',
        'quote_time',
        'last_price',
        'change_amount',
        'pct_change',
        'open',
        'high',
        'low',
        'pre_close',
        'volume',
        'amount',
        'source_timestamp',
        'metadata',
    ];

    protected $casts = [
        'quote_time' => 'datetime',
        'source_timestamp' => 'datetime',
        'metadata' => 'array',
        'last_price' => 'decimal:4',
        'change_amount' => 'decimal:4',
        'pct_change' => 'decimal:6',
        'open' => 'decimal:4',
        'high' => 'decimal:4',
        'low' => 'decimal:4',
        'pre_close' => 'decimal:4',
        'volume' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function dailyBars(): HasMany
    {
        return $this->hasMany(IndexDailyBar::class);
    }
}

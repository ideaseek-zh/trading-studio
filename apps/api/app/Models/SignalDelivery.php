<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'trading_signal_id',
        'signal_subscription_id',
        'delivery_channel',
        'delivery_status',
        'attempts',
        'response_status',
        'response_body',
        'payload',
        'last_attempted_at',
        'next_retry_at',
        'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_attempted_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(TradingSignal::class, 'trading_signal_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SignalSubscription::class, 'signal_subscription_id');
    }
}

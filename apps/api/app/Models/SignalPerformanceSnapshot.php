<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalPerformanceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'trading_signal_id',
        'horizon_days',
        'evaluation_status',
        'benchmark_code',
        'entry_trade_date',
        'exit_trade_date',
        'holding_days',
        'entry_price',
        'exit_price',
        'return_pct',
        'benchmark_return_pct',
        'alpha_return_pct',
        'max_upside_pct',
        'max_drawdown_pct',
        'win_probability',
        'coverage_pct',
        'evaluated_at',
        'metrics',
    ];

    protected $casts = [
        'entry_trade_date' => 'date',
        'exit_trade_date' => 'date',
        'entry_price' => 'decimal:4',
        'exit_price' => 'decimal:4',
        'return_pct' => 'decimal:4',
        'benchmark_return_pct' => 'decimal:4',
        'alpha_return_pct' => 'decimal:4',
        'max_upside_pct' => 'decimal:4',
        'max_drawdown_pct' => 'decimal:4',
        'win_probability' => 'decimal:2',
        'coverage_pct' => 'decimal:2',
        'evaluated_at' => 'datetime',
        'metrics' => 'array',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(TradingSignal::class, 'trading_signal_id');
    }
}

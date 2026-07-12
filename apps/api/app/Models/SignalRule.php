<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'rule_key',
        'name',
        'description',
        'scope_type',
        'chain_type',
        'signal_type',
        'default_direction',
        'horizon_label',
        'horizon_days',
        'min_signal_score',
        'enabled',
        'weight_profile',
        'trigger_conditions',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'weight_profile' => 'array',
        'trigger_conditions' => 'array',
        'min_signal_score' => 'decimal:2',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(TradingSignal::class, 'signal_rule_id');
    }
}

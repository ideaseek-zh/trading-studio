<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyWorkspaceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_workspace_id',
        'security_id',
        'item_type',
        'status',
        'position_quantity',
        'average_cost',
        'target_price',
        'stop_loss_price',
        'alert_score_threshold',
        'position_weight_bps',
        'review_cadence',
        'notes',
        'tags',
        'alert_preferences',
        'last_reviewed_at',
    ];

    protected $casts = [
        'strategy_workspace_id' => 'integer',
        'security_id' => 'integer',
        'position_quantity' => 'decimal:4',
        'average_cost' => 'decimal:4',
        'target_price' => 'decimal:4',
        'stop_loss_price' => 'decimal:4',
        'alert_score_threshold' => 'decimal:2',
        'position_weight_bps' => 'integer',
        'tags' => 'array',
        'alert_preferences' => 'array',
        'last_reviewed_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StrategyWorkspace::class, 'strategy_workspace_id');
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class);
    }
}

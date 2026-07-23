<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StrategyWorkspaceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'strategy_workspace_id' => $this->strategy_workspace_id,
            'security_id' => $this->security_id,
            'item_type' => $this->item_type,
            'status' => $this->status,
            'position_quantity' => $this->position_quantity !== null ? (float) $this->position_quantity : null,
            'average_cost' => $this->average_cost !== null ? (float) $this->average_cost : null,
            'target_price' => $this->target_price !== null ? (float) $this->target_price : null,
            'stop_loss_price' => $this->stop_loss_price !== null ? (float) $this->stop_loss_price : null,
            'alert_score_threshold' => (float) $this->alert_score_threshold,
            'position_weight_bps' => $this->position_weight_bps,
            'review_cadence' => $this->review_cadence,
            'notes' => $this->notes,
            'tags' => $this->tags,
            'alert_preferences' => $this->alert_preferences,
            'last_reviewed_at' => optional($this->last_reviewed_at)?->toAtomString(),
            'security' => $this->when($this->relationLoaded('security') && $this->security !== null, fn () => new SecurityResource($this->security)),
            'created_at' => optional($this->created_at)?->toAtomString(),
            'updated_at' => optional($this->updated_at)?->toAtomString(),
        ];
    }
}

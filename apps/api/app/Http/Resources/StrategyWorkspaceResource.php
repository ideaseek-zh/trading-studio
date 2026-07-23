<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StrategyWorkspaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_key' => $this->workspace_key,
            'name' => $this->name,
            'owner_key' => $this->owner_key,
            'workspace_type' => $this->workspace_type,
            'risk_profile' => $this->risk_profile,
            'base_currency' => $this->base_currency,
            'default_signal_subscription_id' => $this->default_signal_subscription_id,
            'settings' => $this->settings,
            'enabled' => $this->enabled,
            'last_reviewed_at' => optional($this->last_reviewed_at)?->toAtomString(),
            'items_count' => $this->when(isset($this->items_count), $this->items_count),
            'default_signal_subscription' => $this->when(
                $this->relationLoaded('defaultSignalSubscription') && $this->defaultSignalSubscription !== null,
                fn () => new SignalSubscriptionResource($this->defaultSignalSubscription)
            ),
            'items' => $this->when(
                $this->relationLoaded('items'),
                fn () => StrategyWorkspaceItemResource::collection($this->items)
            ),
            'created_at' => optional($this->created_at)?->toAtomString(),
            'updated_at' => optional($this->updated_at)?->toAtomString(),
        ];
    }
}

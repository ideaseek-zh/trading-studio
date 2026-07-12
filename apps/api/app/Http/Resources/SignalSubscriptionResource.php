<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignalSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscriber_key' => $this->subscriber_key,
            'subscriber_name' => $this->subscriber_name,
            'channel_type' => $this->channel_type,
            'priority_level' => $this->priority_level,
            'priority_order' => $this->priority_order,
            'endpoint_url' => $this->endpoint_url,
            'min_signal_score' => (float) $this->min_signal_score,
            'enabled' => $this->enabled,
            'filters' => $this->filters,
            'last_notified_at' => optional($this->last_notified_at)?->toAtomString(),
            'deliveries_count' => $this->when(isset($this->deliveries_count), $this->deliveries_count),
            'created_at' => optional($this->created_at)?->toAtomString(),
            'updated_at' => optional($this->updated_at)?->toAtomString(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Support\SensitiveValueMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignalDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'delivery_channel' => $this->delivery_channel,
            'delivery_status' => $this->delivery_status,
            'batch_key' => $this->batch_key,
            'suppression_reason' => $this->suppression_reason,
            'attempts' => $this->attempts,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'payload' => $this->payload,
            'dispatch_context' => $this->dispatch_context,
            'last_attempted_at' => optional($this->last_attempted_at)?->toAtomString(),
            'next_retry_at' => optional($this->next_retry_at)?->toAtomString(),
            'delivered_at' => optional($this->delivered_at)?->toAtomString(),
            'created_at' => optional($this->created_at)?->toAtomString(),
            'updated_at' => optional($this->updated_at)?->toAtomString(),
            'subscription' => $this->when($this->relationLoaded('subscription') && $this->subscription !== null, [
                'id' => $this->subscription->id,
                'subscriber_key' => $this->subscription->subscriber_key,
                'subscriber_name' => $this->subscription->subscriber_name,
                'priority_level' => $this->subscription->priority_level,
                'priority_order' => $this->subscription->priority_order,
                'enabled' => $this->subscription->enabled,
                'endpoint_url' => SensitiveValueMasker::maskUrl($this->subscription->endpoint_url),
            ]),
            'signal' => $this->when($this->relationLoaded('signal') && $this->signal !== null, [
                'id' => $this->signal->id,
                'signal_key' => $this->signal->signal_key,
                'signal_type' => $this->signal->signal_type,
                'direction' => $this->signal->direction,
                'title' => $this->signal->title,
                'signal_score' => (float) $this->signal->signal_score,
                'published_at' => optional($this->signal->published_at)?->toAtomString(),
                'primary_security' => $this->signal->primarySecurity ? [
                    'id' => $this->signal->primarySecurity->id,
                    'symbol' => $this->signal->primarySecurity->symbol,
                    'name' => $this->signal->primarySecurity->name,
                ] : null,
                'latest_event' => $this->signal->latestEvent ? [
                    'id' => $this->signal->latestEvent->id,
                    'event_type' => $this->signal->latestEvent->event_type,
                    'title' => $this->signal->latestEvent->title,
                    'timeline_stage' => $this->signal->latestEvent->timeline_stage,
                ] : null,
            ]),
        ];
    }
}

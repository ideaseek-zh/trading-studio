<?php

namespace App\Http\Resources;

use App\Support\SensitiveValueMasker;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            'endpoint_url' => SensitiveValueMasker::maskUrl($this->endpoint_url),
            'endpoint_url_masked' => SensitiveValueMasker::maskUrl($this->endpoint_url),
            'endpoint_configured' => filled($this->endpoint_url),
            'secret_token_configured' => filled($this->secret_token),
            'notification_template_id' => $this->notification_template_id,
            'notification_channel_credential_id' => $this->notification_channel_credential_id,
            'notification_template' => $this->when(
                $this->resource->relationLoaded('notificationTemplate') && $this->notificationTemplate !== null,
                fn () => new NotificationTemplateResource($this->notificationTemplate)
            ),
            'notification_channel_credential' => $this->when(
                $this->resource->relationLoaded('notificationChannelCredential') && $this->notificationChannelCredential !== null,
                fn () => new NotificationChannelCredentialResource($this->notificationChannelCredential)
            ),
            'min_signal_score' => (float) $this->min_signal_score,
            'enabled' => $this->enabled,
            'channel_routes' => collect($this->channel_routes ?? [])
                ->map(fn (array $route): array => [
                    'route_key' => $route['route_key'] ?? null,
                    'label' => $route['label'] ?? null,
                    'channel_type' => $route['channel_type'] ?? null,
                    'target' => SensitiveValueMasker::maskUrl($route['target'] ?? null),
                    'target_masked' => SensitiveValueMasker::maskUrl($route['target'] ?? null),
                    'target_configured' => filled($route['target'] ?? null) || filled($route['credential_id'] ?? null),
                    'secret_token_configured' => filled($route['secret_token'] ?? null),
                    'signature_mode' => $route['signature_mode'] ?? 'header_token',
                    'message_format' => $route['message_format'] ?? null,
                    'template_id' => $route['template_id'] ?? null,
                    'credential_id' => $route['credential_id'] ?? null,
                    'enabled' => (bool) ($route['enabled'] ?? true),
                    'priority_order' => (int) ($route['priority_order'] ?? 100),
                    'delivery_tier' => $route['delivery_tier'] ?? 'primary',
                ])
                ->values()
                ->all(),
            'filters' => $this->filters,
            'quiet_hours' => $this->quiet_hours,
            'escalation_rules' => $this->escalation_rules,
            'debounce_window_minutes' => $this->debounce_window_minutes,
            'merge_window_minutes' => $this->merge_window_minutes,
            'max_merge_signals' => $this->max_merge_signals,
            'last_notified_at' => optional($this->last_notified_at)?->toAtomString(),
            'deliveries_count' => $this->when(isset($this->deliveries_count), $this->deliveries_count),
            'recent_deliveries' => $this->when(
                $this->resource->relationLoaded('recentDeliveries'),
                fn () => SignalDeliveryResource::collection(
                    $this->resource->getRelation('recentDeliveries') instanceof EloquentCollection
                        ? $this->resource->getRelation('recentDeliveries')
                        : collect()
                )
            ),
            'created_at' => optional($this->created_at)?->toAtomString(),
            'updated_at' => optional($this->updated_at)?->toAtomString(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SignalDeliveryResource;
use App\Http\Resources\SignalSubscriptionResource;
use App\Models\NotificationChannelCredential;
use App\Models\SignalDelivery;
use App\Models\SignalSubscription;
use App\Services\SignalDeliveryService;
use App\Support\SensitiveValueMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SignalSubscriptionController extends Controller
{
    public function __construct(
        private readonly SignalDeliveryService $deliveryService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $subscriptions = SignalSubscription::query()
            ->with(['notificationTemplate', 'notificationChannelCredential'])
            ->withCount('deliveries')
            ->when(
                $request->string('subscriberKey')->isNotEmpty(),
                fn ($query) => $query->where('subscriber_key', $request->string('subscriberKey')->toString())
            )
            ->when(
                $request->string('priorityLevel')->isNotEmpty(),
                fn ($query) => $query->where('priority_level', $request->string('priorityLevel')->toString())
            )
            ->when(
                $request->has('enabled'),
                fn ($query) => $query->where('enabled', $request->boolean('enabled'))
            )
            ->when(
                $request->string('channelType')->isNotEmpty(),
                fn ($query) => $query->where('channel_type', $request->string('channelType')->toString())
            )
            ->orderBy('priority_order')
            ->orderByDesc('id')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => SignalSubscriptionResource::collection($subscriptions->items()),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateSubscription($request, false);
        $primaryCredential = $this->resolvePrimaryCredential($validated, null);

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => $validated['subscriber_key'],
            'subscriber_name' => $validated['subscriber_name'] ?? null,
            'channel_type' => $validated['channel_type'],
            'priority_level' => $validated['priority_level'] ?? 'normal',
            'priority_order' => $validated['priority_order'] ?? 100,
            'endpoint_url' => $this->resolvePrimaryEndpointUrl($validated, $primaryCredential),
            'secret_token' => $validated['secret_token'] ?? null,
            'notification_template_id' => $validated['notification_template_id'] ?? null,
            'notification_channel_credential_id' => $validated['notification_channel_credential_id'] ?? null,
            'channel_routes' => $this->normalizeChannelRoutes($validated, null),
            'min_signal_score' => $validated['min_signal_score'] ?? 60,
            'enabled' => $validated['enabled'] ?? true,
            'filters' => $validated['filters'] ?? [],
            'quiet_hours' => $validated['quiet_hours'] ?? null,
            'escalation_rules' => $validated['escalation_rules'] ?? [],
            'debounce_window_minutes' => $validated['debounce_window_minutes'] ?? 5,
            'merge_window_minutes' => $validated['merge_window_minutes'] ?? 0,
            'max_merge_signals' => $validated['max_merge_signals'] ?? 5,
        ]);
        $subscription->load(['notificationTemplate', 'notificationChannelCredential']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new SignalSubscriptionResource($subscription),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], 201);
    }

    public function show(SignalSubscription $subscription): JsonResponse
    {
        $subscription->load(['notificationTemplate', 'notificationChannelCredential'])->loadCount('deliveries');
        $subscription->setRelation('recentDeliveries', SignalDelivery::query()
            ->with(['signal.primarySecurity', 'signal.latestEvent', 'subscription'])
            ->where('signal_subscription_id', $subscription->id)
            ->latest('created_at')
            ->limit(10)
            ->get());

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new SignalSubscriptionResource($subscription),
            'meta' => [
                'recent_deliveries' => SignalDeliveryResource::collection($subscription->getRelation('recentDeliveries')),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function update(Request $request, SignalSubscription $subscription): JsonResponse
    {
        $validated = $this->validateSubscription($request, true);
        $primaryCredential = $this->resolvePrimaryCredential($validated, $subscription);

        $subscription->fill([
            'subscriber_key' => $validated['subscriber_key'] ?? $subscription->subscriber_key,
            'subscriber_name' => $validated['subscriber_name'] ?? $subscription->subscriber_name,
            'channel_type' => $validated['channel_type'] ?? $subscription->channel_type,
            'priority_level' => $validated['priority_level'] ?? $subscription->priority_level,
            'priority_order' => $validated['priority_order'] ?? $subscription->priority_order,
            'endpoint_url' => $this->resolvePrimaryEndpointUrl($validated, $primaryCredential, $subscription),
            'secret_token' => array_key_exists('secret_token', $validated) ? $validated['secret_token'] : $subscription->secret_token,
            'notification_template_id' => $validated['notification_template_id'] ?? $subscription->notification_template_id,
            'notification_channel_credential_id' => $validated['notification_channel_credential_id'] ?? $subscription->notification_channel_credential_id,
            'min_signal_score' => $validated['min_signal_score'] ?? $subscription->min_signal_score,
            'enabled' => $validated['enabled'] ?? $subscription->enabled,
            'filters' => $validated['filters'] ?? $subscription->filters,
            'quiet_hours' => $validated['quiet_hours'] ?? $subscription->quiet_hours,
            'escalation_rules' => $validated['escalation_rules'] ?? $subscription->escalation_rules,
            'debounce_window_minutes' => $validated['debounce_window_minutes'] ?? $subscription->debounce_window_minutes,
            'merge_window_minutes' => $validated['merge_window_minutes'] ?? $subscription->merge_window_minutes,
            'max_merge_signals' => $validated['max_merge_signals'] ?? $subscription->max_merge_signals,
        ]);

        if ($request->hasAny([
            'channel_routes',
            'channel_type',
            'endpoint_url',
            'secret_token',
            'notification_template_id',
            'notification_channel_credential_id',
        ])) {
            $subscription->channel_routes = $this->normalizeChannelRoutes(array_merge($subscription->toArray(), $validated), $subscription);
        }

        $subscription->save();
        $subscription->load(['notificationTemplate', 'notificationChannelCredential']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new SignalSubscriptionResource($subscription),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function destroy(SignalSubscription $subscription): JsonResponse
    {
        $subscription->delete();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'deleted' => true,
                'id' => $subscription->id,
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function test(Request $request, SignalSubscription $subscription): JsonResponse
    {
        $request->validate([
            'signal_id' => ['nullable', 'integer', 'exists:trading_signals,id'],
        ]);

        $signal = $request->filled('signal_id')
            ? \App\Models\TradingSignal::query()->with(['eventChain', 'primarySecurity'])->find((int) $request->input('signal_id'))
            : $this->deliveryService->latestMatchingSignal($subscription);

        if ($signal === null) {
            return response()->json([
                'code' => 1,
                'message' => 'no_matching_signal',
                'data' => [
                    'tested' => false,
                ],
                'meta' => [
                    'timestamp' => now()->toAtomString(),
                ],
            ], 422);
        }

        $testResults = $this->deliveryService->testSubscriptionRoutes($subscription, $signal);
        $ok = collect($testResults)->contains(fn (array $item): bool => $item['ok']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'tested' => true,
                'ok' => $ok,
                'results' => $testResults,
                'signal' => [
                    'id' => $signal->id,
                    'title' => $signal->title,
                    'signal_type' => $signal->signal_type,
                ],
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], $ok ? 200 : 502);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSubscription(Request $request, bool $partial): array
    {
        $validated = $request->validate([
            'subscriber_key' => [$partial ? 'sometimes' : 'required', 'string', 'max:128'],
            'subscriber_name' => ['nullable', 'string', 'max:128'],
            'channel_type' => [$partial ? 'sometimes' : 'required', 'string', Rule::in(['webhook', 'wecom_bot', 'dingtalk_bot', 'feishu_bot', 'email'])],
            'priority_level' => ['nullable', 'string', Rule::in(['critical', 'high', 'normal', 'low'])],
            'priority_order' => ['nullable', 'integer', 'between:1,9999'],
            'endpoint_url' => ['nullable', 'string', 'max:4000'],
            'secret_token' => ['nullable', 'string', 'max:4000'],
            'notification_template_id' => ['nullable', 'integer', 'exists:notification_templates,id'],
            'notification_channel_credential_id' => ['nullable', 'integer', 'exists:notification_channel_credentials,id'],
            'channel_routes' => ['nullable', 'array'],
            'channel_routes.*.route_key' => ['nullable', 'string', 'max:64'],
            'channel_routes.*.label' => ['nullable', 'string', 'max:128'],
            'channel_routes.*.channel_type' => ['required_with:channel_routes', 'string', Rule::in(['webhook', 'wecom_bot', 'dingtalk_bot', 'feishu_bot', 'email'])],
            'channel_routes.*.target' => ['nullable', 'string', 'max:4000'],
            'channel_routes.*.secret_token' => ['nullable', 'string', 'max:4000'],
            'channel_routes.*.signature_mode' => ['nullable', 'string', Rule::in(['none', 'header_token', 'hmac_sha256', 'feishu_v1'])],
            'channel_routes.*.message_format' => ['nullable', 'string', Rule::in(['text', 'markdown', 'post'])],
            'channel_routes.*.template_id' => ['nullable', 'integer', 'exists:notification_templates,id'],
            'channel_routes.*.credential_id' => ['nullable', 'integer', 'exists:notification_channel_credentials,id'],
            'channel_routes.*.enabled' => ['nullable', 'boolean'],
            'channel_routes.*.priority_order' => ['nullable', 'integer', 'between:1,9999'],
            'channel_routes.*.delivery_tier' => ['nullable', 'string', Rule::in(['primary', 'escalation'])],
            'min_signal_score' => ['nullable', 'numeric', 'between:0,100'],
            'enabled' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.security_symbols' => ['nullable', 'array'],
            'filters.security_symbols.*' => ['string', 'max:16'],
            'filters.chain_types' => ['nullable', 'array'],
            'filters.chain_types.*' => ['string', 'max:64'],
            'filters.signal_types' => ['nullable', 'array'],
            'filters.signal_types.*' => ['string', 'max:64'],
            'filters.directions' => ['nullable', 'array'],
            'filters.directions.*' => ['string', 'max:16'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.enabled' => ['nullable', 'boolean'],
            'quiet_hours.timezone' => ['nullable', 'string', 'max:64'],
            'quiet_hours.start' => ['nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['nullable', 'date_format:H:i'],
            'escalation_rules' => ['nullable', 'array'],
            'escalation_rules.*.after_attempts' => ['required_with:escalation_rules', 'integer', 'between:1,10'],
            'escalation_rules.*.route_keys' => ['nullable', 'array'],
            'escalation_rules.*.route_keys.*' => ['string', 'max:64'],
            'escalation_rules.*.channel_types' => ['nullable', 'array'],
            'escalation_rules.*.channel_types.*' => ['string', Rule::in(['webhook', 'wecom_bot', 'dingtalk_bot', 'feishu_bot', 'email'])],
            'debounce_window_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'merge_window_minutes' => ['nullable', 'integer', 'between:0,1440'],
            'max_merge_signals' => ['nullable', 'integer', 'between:1,50'],
        ]);

        if (array_key_exists('endpoint_url', $validated) && SensitiveValueMasker::looksMasked($validated['endpoint_url'])) {
            unset($validated['endpoint_url']);
        }

        if (array_key_exists('secret_token', $validated) && SensitiveValueMasker::looksMasked($validated['secret_token'])) {
            unset($validated['secret_token']);
        }

        if (! $partial && empty($validated['endpoint_url']) && empty($validated['notification_channel_credential_id'])) {
            throw ValidationException::withMessages([
                'endpoint_url' => '主通道目标或默认渠道凭证至少要提供一个。',
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    private function normalizeChannelRoutes(array $validated, ?SignalSubscription $existing): array
    {
        $existingRoutes = collect($existing?->channel_routes ?? [])
            ->filter(fn ($route): bool => is_array($route))
            ->keyBy(fn (array $route): string => (string) ($route['route_key'] ?? ''));

        $routes = collect($validated['channel_routes'] ?? [])
            ->filter(fn ($route): bool => is_array($route))
            ->map(function (array $route) use ($existingRoutes): array {
                $routeKey = (string) ($route['route_key'] ?? Str::slug((string) ($route['label'] ?? $route['channel_type'] ?? 'route'), '_'));
                $existingRoute = (array) $existingRoutes->get($routeKey, []);
                $target = (string) ($route['target'] ?? '');

                if (SensitiveValueMasker::looksMasked($target)) {
                    $target = (string) ($existingRoute['target'] ?? '');
                }

                $secretToken = $route['secret_token'] ?? null;
                if (is_string($secretToken) && SensitiveValueMasker::looksMasked($secretToken)) {
                    $secretToken = $existingRoute['secret_token'] ?? null;
                }

                return [
                    'route_key' => $routeKey,
                    'label' => (string) ($route['label'] ?? $route['channel_type'] ?? 'route'),
                    'channel_type' => (string) ($route['channel_type'] ?? 'webhook'),
                    'target' => $target,
                    'secret_token' => $secretToken,
                    'signature_mode' => (string) ($route['signature_mode'] ?? $existingRoute['signature_mode'] ?? 'header_token'),
                    'message_format' => $route['message_format'] ?? $existingRoute['message_format'] ?? null,
                    'template_id' => isset($route['template_id']) ? (int) $route['template_id'] : ($existingRoute['template_id'] ?? null),
                    'credential_id' => isset($route['credential_id']) ? (int) $route['credential_id'] : ($existingRoute['credential_id'] ?? null),
                    'enabled' => (bool) ($route['enabled'] ?? true),
                    'priority_order' => (int) ($route['priority_order'] ?? 100),
                    'delivery_tier' => (string) ($route['delivery_tier'] ?? 'primary'),
                ];
            })
            ->filter(fn (array $route): bool => $route['target'] !== '' || ! empty($route['credential_id']))
            ->values();

        if ($routes->isEmpty() && (! empty($validated['endpoint_url']) || ! empty($validated['notification_channel_credential_id'])) && ! empty($validated['channel_type'])) {
            $routes = collect([[
                'route_key' => 'primary_'.(string) $validated['channel_type'],
                'label' => 'Primary '.strtoupper((string) $validated['channel_type']),
                'channel_type' => (string) $validated['channel_type'],
                'target' => (string) ($validated['endpoint_url'] ?? ''),
                'secret_token' => $validated['secret_token'] ?? null,
                'signature_mode' => (string) (($validated['channel_type'] ?? 'webhook') === 'feishu_bot' ? 'feishu_v1' : 'header_token'),
                'message_format' => null,
                'template_id' => $validated['notification_template_id'] ?? null,
                'credential_id' => $validated['notification_channel_credential_id'] ?? null,
                'enabled' => true,
                'priority_order' => 1,
                'delivery_tier' => 'primary',
            ]]);
        }

        return $routes->values()->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolvePrimaryCredential(array $validated, ?SignalSubscription $subscription): ?NotificationChannelCredential
    {
        $credentialId = $validated['notification_channel_credential_id'] ?? $subscription?->notification_channel_credential_id;

        return $credentialId === null
            ? null
            : NotificationChannelCredential::query()->find((int) $credentialId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolvePrimaryEndpointUrl(
        array $validated,
        ?NotificationChannelCredential $credential,
        ?SignalSubscription $subscription = null
    ): string {
        $endpointUrl = (string) ($validated['endpoint_url'] ?? $subscription?->endpoint_url ?? $credential?->endpoint_url ?? '');

        if ($endpointUrl === '' && $credential?->endpoint_url) {
            $endpointUrl = $credential->endpoint_url;
        }

        if ($endpointUrl === '') {
            throw ValidationException::withMessages([
                'endpoint_url' => '无法解析主通道目标，请提供 endpoint_url 或可用的渠道凭证。',
            ]);
        }

        return $endpointUrl;
    }
}

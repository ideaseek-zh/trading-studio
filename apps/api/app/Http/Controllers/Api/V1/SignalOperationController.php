<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SignalDeliveryResource;
use App\Models\Security;
use App\Models\SignalDelivery;
use App\Models\SignalSubscription;
use App\Models\TradingSignal;
use App\Services\IntelligenceClient;
use App\Services\SignalDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SignalOperationController extends Controller
{
    public function __construct(
        private readonly IntelligenceClient $intelligenceClient,
        private readonly SignalDeliveryService $deliveryService
    ) {
    }

    public function dashboard(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'overview' => $this->overview(),
                'recent_failures' => SignalDeliveryResource::collection($this->recentFailures()),
                'retry_backlog' => SignalDeliveryResource::collection($this->retryBacklogItems()),
                'recent_audit' => SignalDeliveryResource::collection($this->recentAudit()),
                'subscription_health' => $this->subscriptionHealth(),
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'symbol' => ['nullable', 'string', 'max:16'],
            'signal_id' => ['nullable', 'integer', 'exists:trading_signals,id'],
            'event_chain_id' => ['nullable', 'integer', 'exists:event_chains,id'],
            'rebuild_signals' => ['nullable', 'boolean'],
            'evaluate_insights' => ['nullable', 'boolean'],
            'enqueue_deliveries' => ['nullable', 'boolean'],
            'dispatch_webhooks' => ['nullable', 'boolean'],
            'dispatch_limit' => ['nullable', 'integer', 'between:1,500'],
        ]);

        $actions = [
            'rebuild_signals' => $validated['rebuild_signals'] ?? false,
            'evaluate_insights' => $validated['evaluate_insights'] ?? false,
            'enqueue_deliveries' => $validated['enqueue_deliveries'] ?? false,
            'dispatch_webhooks' => $validated['dispatch_webhooks'] ?? false,
        ];

        if (! in_array(true, $actions, true)) {
            return response()->json([
                'code' => 1,
                'message' => 'no_actions_selected',
                'data' => [
                    'accepted' => false,
                ],
                'meta' => [
                    'timestamp' => now()->toAtomString(),
                ],
            ], 422);
        }

        $securityId = null;
        if (! empty($validated['symbol'])) {
            $security = Security::query()->where('symbol', trim((string) $validated['symbol']))->first();
            if ($security === null) {
                return response()->json([
                    'code' => 1,
                    'message' => 'security_not_found',
                    'data' => [
                        'accepted' => false,
                    ],
                    'meta' => [
                        'timestamp' => now()->toAtomString(),
                    ],
                ], 422);
            }

            $securityId = $security->id;
        }

        $signalId = $validated['signal_id'] ?? null;
        $eventChainId = $validated['event_chain_id'] ?? null;
        $dispatchLimit = $validated['dispatch_limit'] ?? 50;

        $results = [];

        if ($actions['rebuild_signals']) {
            $results['rebuild_signals'] = $this->wrapAction(fn () => $this->intelligenceClient->rebuildSignals($securityId, $eventChainId));
        }

        if ($actions['evaluate_insights']) {
            $results['evaluate_insights'] = $this->wrapAction(fn () => $this->intelligenceClient->rebuildSignalInsights($signalId, $securityId));
        }

        if ($actions['enqueue_deliveries']) {
            $results['enqueue_deliveries'] = $this->wrapAction(
                fn (): array => ['created' => $this->deliveryService->enqueuePendingDeliveries()]
            );
        }

        if ($actions['dispatch_webhooks']) {
            $results['dispatch_webhooks'] = $this->wrapAction(
                fn (): array => $this->deliveryService->dispatchPendingWebhooks((int) $dispatchLimit)
            );
        }

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'accepted' => true,
                'results' => $results,
                'overview' => $this->overview(),
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function retryBacklog(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'delivery_ids' => ['nullable', 'array'],
            'delivery_ids.*' => ['integer', 'exists:signal_deliveries,id'],
            'limit' => ['nullable', 'integer', 'between:1,500'],
            'dispatch_after' => ['nullable', 'boolean'],
        ]);

        $limit = $validated['limit'] ?? 50;
        $dispatchAfter = $validated['dispatch_after'] ?? true;
        $deliveryIds = $validated['delivery_ids'] ?? [];

        $resetCount = $this->deliveryService->retryDeliveries($deliveryIds, $limit);
        $dispatchResult = $dispatchAfter ? $this->deliveryService->dispatchPendingWebhooks($limit) : null;

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'reset_count' => $resetCount,
                'dispatch_result' => $dispatchResult,
                'overview' => $this->overview(),
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    private function overview(): array
    {
        $deliveryRows = SignalDelivery::query()->get();
        $statusCounts = $deliveryRows->pluck('delivery_status')->countBy()->all();
        $signalRows = TradingSignal::query()->get();

        return [
            'deliveries_total' => $deliveryRows->count(),
            'queued' => $statusCounts['queued'] ?? 0,
            'retrying' => $statusCounts['retrying'] ?? 0,
            'failed' => $statusCounts['failed'] ?? 0,
            'success' => $statusCounts['success'] ?? 0,
            'partial_success' => $statusCounts['partial_success'] ?? 0,
            'suppressed' => $statusCounts['suppressed'] ?? 0,
            'merged' => $statusCounts['merged'] ?? 0,
            'skipped' => $statusCounts['skipped'] ?? 0,
            'due_retry' => $deliveryRows->filter(fn (SignalDelivery $delivery): bool => in_array($delivery->delivery_status, ['failed', 'retrying', 'suppressed'], true)
                && ($delivery->next_retry_at === null || $delivery->next_retry_at->lte(now())))->count(),
            'active_signals' => $signalRows->where('status', 'active')->count(),
            'pending_evaluation' => $signalRows->filter(fn (TradingSignal $signal): bool => ($signal->performance_summary['evaluation_status'] ?? 'pending') !== 'evaluated')->count(),
            'enabled_subscriptions' => SignalSubscription::query()->where('enabled', true)->count(),
        ];
    }

    private function subscriptionHealth(): array
    {
        $subscriptions = SignalSubscription::query()
            ->withCount('deliveries')
            ->orderBy('priority_order')
            ->limit(12)
            ->get();

        return [
            'total' => SignalSubscription::query()->count(),
            'enabled' => SignalSubscription::query()->where('enabled', true)->count(),
            'disabled' => SignalSubscription::query()->where('enabled', false)->count(),
            'items' => $subscriptions->map(fn (SignalSubscription $subscription): array => [
                'id' => $subscription->id,
                'subscriber_key' => $subscription->subscriber_key,
                'subscriber_name' => $subscription->subscriber_name,
                'priority_level' => $subscription->priority_level,
                'priority_order' => $subscription->priority_order,
                'enabled' => $subscription->enabled,
                'min_signal_score' => (float) $subscription->min_signal_score,
                'deliveries_count' => $subscription->deliveries_count,
                'last_notified_at' => optional($subscription->last_notified_at)?->toAtomString(),
            ])->values()->all(),
        ];
    }

    private function recentFailures()
    {
        return SignalDelivery::query()
            ->with(['signal.primarySecurity', 'signal.latestEvent', 'subscription'])
            ->whereIn('delivery_status', ['failed', 'retrying', 'suppressed'])
            ->orderByDesc('last_attempted_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    private function retryBacklogItems()
    {
        return SignalDelivery::query()
            ->with(['signal.primarySecurity', 'signal.latestEvent', 'subscription'])
            ->whereIn('delivery_status', ['failed', 'retrying', 'queued', 'suppressed'])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('next_retry_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    private function recentAudit()
    {
        return SignalDelivery::query()
            ->with(['signal.primarySecurity', 'signal.latestEvent', 'subscription'])
            ->latest('created_at')
            ->limit(12)
            ->get();
    }

    private function wrapAction(callable $callback): array
    {
        try {
            $payload = $callback();

            return [
                'ok' => true,
                'payload' => $payload,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'error' => $exception->getMessage(),
            ];
        }
    }
}

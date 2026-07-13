<?php

namespace App\Services;

use App\Mail\SignalAlertMail;
use App\Models\NotificationChannelCredential;
use App\Models\NotificationTemplate;
use App\Models\SignalDelivery;
use App\Models\SignalSubscription;
use App\Models\TradingSignal;
use App\Support\SensitiveValueMasker;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SignalDeliveryService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Mailer $mail,
        private readonly NotificationTemplateRenderer $renderer
    ) {
    }

    public function enqueuePendingDeliveries(): int
    {
        $signals = TradingSignal::query()
            ->with(['eventChain', 'primarySecurity', 'latestEvent'])
            ->where('status', 'active')
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->get();

        $subscriptions = SignalSubscription::query()
            ->with(['notificationTemplate', 'notificationChannelCredential'])
            ->where('enabled', true)
            ->orderBy('priority_order')
            ->orderByDesc('min_signal_score')
            ->get();

        $created = 0;

        foreach ($signals as $signal) {
            foreach ($subscriptions as $subscription) {
                if (! $this->matchesSubscription($signal, $subscription)) {
                    continue;
                }

                $exists = SignalDelivery::query()
                    ->where('trading_signal_id', $signal->id)
                    ->where('signal_subscription_id', $subscription->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                if ($this->isWithinQuietHours($subscription)['active']) {
                    $this->createSuppressedDelivery($signal, $subscription, 'quiet_hours', $this->isWithinQuietHours($subscription)['resume_at']);
                    $created++;
                    continue;
                }

                $mergeCandidate = $this->findMergeCandidate($subscription, $signal);
                if ($mergeCandidate !== null) {
                    $this->appendSignalToBatch($mergeCandidate, $signal, $subscription);
                    $this->createMergedDelivery($signal, $subscription, $mergeCandidate);
                    $created++;
                    continue;
                }

                if ($this->shouldDebounce($subscription, $signal)) {
                    $this->createSuppressedDelivery($signal, $subscription, 'debounce', null);
                    $created++;
                    continue;
                }

                $this->createQueuedDelivery($signal, $subscription);
                $created++;
            }
        }

        return $created;
    }

    public function dispatchPendingWebhooks(int $limit = 50): array
    {
        $deliveries = SignalDelivery::query()
            ->select('signal_deliveries.*')
            ->join('signal_subscriptions', 'signal_subscriptions.id', '=', 'signal_deliveries.signal_subscription_id')
            ->join('trading_signals', 'trading_signals.id', '=', 'signal_deliveries.trading_signal_id')
            ->with(['signal.eventChain', 'signal.primarySecurity', 'signal.latestEvent', 'subscription'])
            ->where(function ($query): void {
                $query->whereIn('signal_deliveries.delivery_status', ['queued', 'retrying'])
                    ->where(function ($pendingQuery): void {
                        $pendingQuery->whereNull('signal_deliveries.next_retry_at')
                            ->orWhere('signal_deliveries.next_retry_at', '<=', now());
                    });
            })
            ->orWhere(function ($query): void {
                $query->where('signal_deliveries.delivery_status', 'suppressed')
                    ->whereNotNull('signal_deliveries.next_retry_at')
                    ->where('signal_deliveries.next_retry_at', '<=', now());
            })
            ->orderBy('signal_subscriptions.priority_order')
            ->orderByDesc('trading_signals.signal_score')
            ->orderBy('signal_deliveries.id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($deliveries as $delivery) {
            $result = $this->dispatchDelivery($delivery);

            if ($result === 'success') {
                $sent++;
            } elseif ($result === 'failed') {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return [
            'queued' => $deliveries->count(),
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    public function dispatchDelivery(SignalDelivery $delivery): string
    {
        $delivery->loadMissing([
            'signal.eventChain',
            'signal.primarySecurity',
            'signal.latestEvent',
            'subscription.notificationTemplate',
            'subscription.notificationChannelCredential',
        ]);

        $subscription = $delivery->subscription;
        $signal = $delivery->signal;

        if ($subscription === null || $signal === null || ! $subscription->enabled) {
            $delivery->update([
                'delivery_status' => 'skipped',
                'last_attempted_at' => now(),
            ]);

            return 'skipped';
        }

        $quietState = $this->isWithinQuietHours($subscription);
        if ($quietState['active']) {
            $delivery->update([
                'delivery_status' => 'suppressed',
                'suppression_reason' => 'quiet_hours',
                'next_retry_at' => $quietState['resume_at'],
                'dispatch_context' => array_merge($delivery->dispatch_context ?? [], [
                    'quiet_hours_rescheduled_at' => optional($quietState['resume_at'])?->toAtomString(),
                ]),
            ]);

            return 'skipped';
        }

        $attemptNumber = (int) $delivery->attempts + 1;
        $routes = $this->routesForAttempt($subscription, $attemptNumber);

        if ($routes === []) {
            $delivery->update([
                'delivery_status' => 'skipped',
                'last_attempted_at' => now(),
                'suppression_reason' => 'no_routes',
            ]);

            return 'skipped';
        }

        $payload = $delivery->payload ?: $this->payload($signal);

        $delivery->update([
            'delivery_status' => 'sending',
            'attempts' => $attemptNumber,
            'last_attempted_at' => now(),
        ]);

        $routeResults = [];

        foreach ($routes as $route) {
            $routeResults[] = $this->dispatchRoute($route, $payload);
        }

        $successCount = collect($routeResults)->where('ok', true)->count();
        $failureCount = count($routeResults) - $successCount;
        $statusCodes = collect($routeResults)
            ->pluck('status')
            ->filter(fn ($status): bool => is_int($status))
            ->values()
            ->all();

        $context = array_merge($delivery->dispatch_context ?? [], [
            'route_results' => $routeResults,
            'route_count' => count($routeResults),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
        ]);

        $summary = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($successCount === 0) {
            $this->markRetry(
                $delivery,
                $statusCodes === [] ? null : max($statusCodes),
                $summary === false ? 'dispatch_failed' : $summary
            );

            $delivery->update([
                'dispatch_context' => $context,
            ]);

            return 'failed';
        }

        $delivery->update([
            'delivery_status' => $failureCount > 0 ? 'partial_success' : 'success',
            'response_status' => $statusCodes === [] ? 200 : max($statusCodes),
            'response_body' => $summary === false ? 'dispatch_success' : mb_substr($summary, 0, 2000),
            'dispatch_context' => $context,
            'delivered_at' => now(),
            'next_retry_at' => null,
        ]);

        $subscription->update(['last_notified_at' => now()]);

        return 'success';
    }

    public function retryDelivery(SignalDelivery $delivery, bool $dispatchNow = true): array
    {
        $this->resetDeliveryForRetry($delivery);
        $delivery->refresh();

        $result = null;
        if ($dispatchNow) {
            $result = $this->dispatchDelivery($delivery);
            $delivery->refresh();
        }

        return [
            'delivery' => $delivery,
            'dispatch_result' => $result,
        ];
    }

    /**
     * @param  array<int>  $deliveryIds
     * @param  array<int, string>  $statuses
     */
    public function retryDeliveries(array $deliveryIds = [], int $limit = 50, array $statuses = ['failed', 'retrying', 'skipped', 'suppressed']): int
    {
        $query = SignalDelivery::query()
            ->whereIn('delivery_status', $statuses)
            ->orderByDesc('last_attempted_at')
            ->orderByDesc('id');

        if ($deliveryIds !== []) {
            $query->whereIn('id', $deliveryIds);
        } else {
            $query->limit($limit);
        }

        $deliveries = $query->get();

        foreach ($deliveries as $delivery) {
            $this->resetDeliveryForRetry($delivery);
        }

        return $deliveries->count();
    }

    public function latestMatchingSignal(SignalSubscription $subscription): ?TradingSignal
    {
        $subscription->loadMissing(['notificationTemplate', 'notificationChannelCredential']);

        return TradingSignal::query()
            ->with(['eventChain', 'primarySecurity', 'latestEvent'])
            ->where('status', 'active')
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->get()
            ->first(fn (TradingSignal $signal): bool => $this->matchesSubscription($signal, $subscription));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function testSubscriptionRoutes(SignalSubscription $subscription, TradingSignal $signal): array
    {
        $subscription->loadMissing(['notificationTemplate', 'notificationChannelCredential']);
        $payload = $this->payload($signal);

        return collect($this->routesForAttempt($subscription, 1))
            ->map(fn (array $route): array => $this->dispatchRoute($route, $payload))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function testCredentialRoute(NotificationChannelCredential $credential, ?NotificationTemplate $template = null): array
    {
        $route = [
            'route_key' => 'credential_'.$credential->id,
            'label' => $credential->name,
            'channel_type' => $credential->channel_type,
            'target' => (string) ($credential->endpoint_url ?? ''),
            'secret_token' => $credential->secret_token,
            'signing_secret' => $credential->signing_secret,
            'signature_mode' => $credential->channel_type === 'feishu_bot' ? 'feishu_v1' : 'header_token',
            'message_format' => $template?->message_format,
            'template' => $template,
            'credential' => $credential,
        ];

        return $this->dispatchRoute($route, $this->systemPayload('渠道凭证联调测试', '这是一条由 Trading Studio 发送的渠道凭证验证通知。'));
    }

    public function matchesSubscription(TradingSignal $signal, SignalSubscription $subscription): bool
    {
        if ((float) $signal->signal_score < (float) $subscription->min_signal_score) {
            return false;
        }

        $filters = $subscription->filters ?? [];
        $securitySymbol = $signal->primarySecurity?->symbol;
        $chainType = $signal->eventChain?->chain_type;

        if (! empty($filters['security_symbols']) && ! in_array($securitySymbol, $filters['security_symbols'], true)) {
            return false;
        }
        if (! empty($filters['chain_types']) && ! in_array($chainType, $filters['chain_types'], true)) {
            return false;
        }
        if (! empty($filters['signal_types']) && ! in_array($signal->signal_type, $filters['signal_types'], true)) {
            return false;
        }
        if (! empty($filters['directions']) && ! in_array($signal->direction, $filters['directions'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batchSignals
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function payload(TradingSignal $signal, array $batchSignals = [], array $meta = []): array
    {
        $signals = $batchSignals !== [] ? $batchSignals : [$this->signalSummary($signal)];

        return [
            'signal' => [
                'id' => $signal->id,
                'signal_key' => $signal->signal_key,
                'signal_type' => $signal->signal_type,
                'direction' => $signal->direction,
                'horizon_label' => $signal->horizon_label,
                'status' => $signal->status,
                'title' => $signal->title,
                'summary' => $signal->summary,
                'signal_score' => (float) $signal->signal_score,
                'confidence_score' => (float) $signal->confidence_score,
                'urgency_score' => (float) $signal->urgency_score,
                'impact_score' => (float) $signal->impact_score,
                'risk_score' => (float) $signal->risk_score,
                'triggered_at' => optional($signal->triggered_at)?->toAtomString(),
                'published_at' => optional($signal->published_at)?->toAtomString(),
                'expires_at' => optional($signal->expires_at)?->toAtomString(),
                'reasoning' => $signal->reasoning,
                'facts' => $signal->facts,
            ],
            'security' => $signal->primarySecurity ? [
                'id' => $signal->primarySecurity->id,
                'symbol' => $signal->primarySecurity->symbol,
                'canonical_symbol' => $signal->primarySecurity->canonical_symbol,
                'name' => $signal->primarySecurity->name,
            ] : null,
            'chain' => $signal->eventChain ? [
                'id' => $signal->eventChain->id,
                'chain_key' => $signal->eventChain->chain_key,
                'chain_type' => $signal->eventChain->chain_type,
                'topic' => $signal->eventChain->topic,
            ] : null,
            'batch_signals' => $signals,
            'meta' => array_merge([
                'delivered_at' => now()->toAtomString(),
                'source' => 'trading-studio',
                'batch_count' => count($signals),
            ], $meta),
        ];
    }

    /**
     * @return array{active: bool, resume_at: ?Carbon}
     */
    private function isWithinQuietHours(SignalSubscription $subscription): array
    {
        $quietHours = $subscription->quiet_hours ?? [];
        $enabled = (bool) ($quietHours['enabled'] ?? false);

        if (! $enabled) {
            return ['active' => false, 'resume_at' => null];
        }

        $timezone = (string) ($quietHours['timezone'] ?? config('app.timezone', 'Asia/Shanghai'));
        $start = (string) ($quietHours['start'] ?? '');
        $end = (string) ($quietHours['end'] ?? '');

        if ($start === '' || $end === '') {
            return ['active' => false, 'resume_at' => null];
        }

        $now = Carbon::now($timezone);
        $startAt = Carbon::parse($now->toDateString().' '.$start, $timezone);
        $endAt = Carbon::parse($now->toDateString().' '.$end, $timezone);

        if ($endAt->lte($startAt)) {
            if ($now->lt($endAt)) {
                $startAt->subDay();
            } else {
                $endAt->addDay();
            }
        }

        $active = $now->between($startAt, $endAt, true);

        return [
            'active' => $active,
            'resume_at' => $active ? $endAt->copy()->setTimezone(config('app.timezone', 'UTC')) : null,
        ];
    }

    private function shouldDebounce(SignalSubscription $subscription, TradingSignal $signal): bool
    {
        $windowMinutes = max((int) $subscription->debounce_window_minutes, 0);
        if ($windowMinutes === 0) {
            return false;
        }

        $cutoff = now()->subMinutes($windowMinutes);

        $recentDeliveries = SignalDelivery::query()
            ->with('signal.primarySecurity')
            ->where('signal_subscription_id', $subscription->id)
            ->whereIn('delivery_status', ['queued', 'retrying', 'sending', 'success', 'partial_success'])
            ->where('created_at', '>=', $cutoff)
            ->latest('id')
            ->limit(20)
            ->get();

        $groupKey = $this->mergeGroupKey($signal);

        return $recentDeliveries->contains(function (SignalDelivery $delivery) use ($groupKey): bool {
            return $delivery->signal !== null && $this->mergeGroupKey($delivery->signal) === $groupKey;
        });
    }

    private function createQueuedDelivery(TradingSignal $signal, SignalSubscription $subscription): void
    {
        $routes = $this->notificationRoutes($subscription);
        $primaryRoute = $routes[0] ?? ['channel_type' => $subscription->channel_type];
        $mergeWindow = max((int) $subscription->merge_window_minutes, 0);
        $batchKey = $mergeWindow > 0 ? (string) Str::uuid() : null;
        $nextRetryAt = $mergeWindow > 0 ? now()->addMinutes($mergeWindow) : null;
        $batchSignals = [$this->signalSummary($signal)];

        SignalDelivery::query()->create([
            'trading_signal_id' => $signal->id,
            'signal_subscription_id' => $subscription->id,
            'delivery_channel' => (string) ($primaryRoute['channel_type'] ?? $subscription->channel_type),
            'delivery_status' => 'queued',
            'batch_key' => $batchKey,
            'suppression_reason' => $mergeWindow > 0 ? 'merge_window' : null,
            'payload' => $this->payload($signal, $batchSignals, [
                'batch_key' => $batchKey,
                'batch_window_ends_at' => optional($nextRetryAt)?->toAtomString(),
            ]),
            'dispatch_context' => [
                'policy' => [
                    'debounce_window_minutes' => (int) $subscription->debounce_window_minutes,
                    'merge_window_minutes' => $mergeWindow,
                ],
            ],
            'next_retry_at' => $nextRetryAt,
        ]);
    }

    private function createSuppressedDelivery(
        TradingSignal $signal,
        SignalSubscription $subscription,
        string $reason,
        ?Carbon $resumeAt
    ): void {
        $routes = $this->notificationRoutes($subscription);
        $primaryRoute = $routes[0] ?? ['channel_type' => $subscription->channel_type];

        SignalDelivery::query()->create([
            'trading_signal_id' => $signal->id,
            'signal_subscription_id' => $subscription->id,
            'delivery_channel' => (string) ($primaryRoute['channel_type'] ?? $subscription->channel_type),
            'delivery_status' => 'suppressed',
            'suppression_reason' => $reason,
            'payload' => $this->payload($signal),
            'dispatch_context' => [
                'suppression_reason' => $reason,
            ],
            'next_retry_at' => $resumeAt,
        ]);
    }

    private function findMergeCandidate(SignalSubscription $subscription, TradingSignal $signal): ?SignalDelivery
    {
        $mergeWindow = max((int) $subscription->merge_window_minutes, 0);
        if ($mergeWindow === 0) {
            return null;
        }

        $cutoff = now()->subMinutes($mergeWindow);

        $candidates = SignalDelivery::query()
            ->with('signal.primarySecurity')
            ->where('signal_subscription_id', $subscription->id)
            ->where('suppression_reason', 'merge_window')
            ->whereIn('delivery_status', ['queued', 'retrying'])
            ->whereNotNull('batch_key')
            ->where('created_at', '>=', $cutoff)
            ->orderByDesc('id')
            ->get();

        $groupKey = $this->mergeGroupKey($signal);

        return $candidates->first(function (SignalDelivery $delivery) use ($groupKey, $subscription): bool {
            if ($delivery->signal === null) {
                return false;
            }

            $batchedSignals = collect($delivery->payload['batch_signals'] ?? []);

            return $this->mergeGroupKey($delivery->signal) === $groupKey
                && $batchedSignals->count() < max((int) $subscription->max_merge_signals, 1);
        });
    }

    private function appendSignalToBatch(SignalDelivery $batchDelivery, TradingSignal $signal, SignalSubscription $subscription): void
    {
        $batchSignals = collect($batchDelivery->payload['batch_signals'] ?? [])
            ->push($this->signalSummary($signal))
            ->unique('id')
            ->take(max((int) $subscription->max_merge_signals, 1))
            ->values()
            ->all();

        $payload = $batchDelivery->payload ?? $this->payload($signal);
        $payload['batch_signals'] = $batchSignals;
        $payload['meta'] = array_merge($payload['meta'] ?? [], [
            'batch_count' => count($batchSignals),
        ]);

        $batchDelivery->update([
            'payload' => $payload,
            'dispatch_context' => array_merge($batchDelivery->dispatch_context ?? [], [
                'merge_count' => count($batchSignals),
            ]),
        ]);
    }

    private function createMergedDelivery(TradingSignal $signal, SignalSubscription $subscription, SignalDelivery $batchDelivery): void
    {
        SignalDelivery::query()->create([
            'trading_signal_id' => $signal->id,
            'signal_subscription_id' => $subscription->id,
            'delivery_channel' => $batchDelivery->delivery_channel,
            'delivery_status' => 'merged',
            'batch_key' => $batchDelivery->batch_key,
            'suppression_reason' => 'merged_into_batch',
            'payload' => $this->payload($signal),
            'dispatch_context' => [
                'batch_key' => $batchDelivery->batch_key,
                'representative_delivery_id' => $batchDelivery->id,
            ],
            'next_retry_at' => $batchDelivery->next_retry_at,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function routesForAttempt(SignalSubscription $subscription, int $attemptNumber): array
    {
        $routes = $this->notificationRoutes($subscription);

        $baseRoutes = collect($routes)
            ->filter(fn (array $route): bool => ($route['delivery_tier'] ?? 'primary') !== 'escalation')
            ->values();

        $escalationRoutes = collect();
        foreach ($subscription->escalation_rules ?? [] as $rule) {
            if ($attemptNumber < (int) ($rule['after_attempts'] ?? 2)) {
                continue;
            }

            $routeKeys = collect($rule['route_keys'] ?? [])->map(fn ($value) => (string) $value)->filter()->all();
            $channelTypes = collect($rule['channel_types'] ?? [])->map(fn ($value) => (string) $value)->filter()->all();

            $matched = collect($routes)->filter(function (array $route) use ($routeKeys, $channelTypes): bool {
                return ($routeKeys !== [] && in_array((string) ($route['route_key'] ?? ''), $routeKeys, true))
                    || ($channelTypes !== [] && in_array((string) ($route['channel_type'] ?? ''), $channelTypes, true))
                    || (($route['delivery_tier'] ?? 'primary') === 'escalation' && $routeKeys === [] && $channelTypes === []);
            });

            $escalationRoutes = $escalationRoutes->merge($matched);
        }

        return $baseRoutes
            ->merge($escalationRoutes)
            ->unique(fn (array $route): string => (string) ($route['route_key'] ?? $route['channel_type'].'_'.$route['target']))
            ->sortBy(fn (array $route): int => (int) ($route['priority_order'] ?? 100))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notificationRoutes(SignalSubscription $subscription): array
    {
        $subscription->loadMissing(['notificationTemplate', 'notificationChannelCredential']);

        $rawRoutes = collect($subscription->channel_routes ?? [])
            ->filter(fn ($route): bool => is_array($route) && ($route['enabled'] ?? true))
            ->values();

        if ($rawRoutes->isEmpty() && $subscription->endpoint_url) {
            $rawRoutes = collect([[
                'route_key' => 'primary_'.$subscription->channel_type,
                'label' => 'Primary '.strtoupper((string) $subscription->channel_type),
                'channel_type' => (string) $subscription->channel_type,
                'target' => (string) $subscription->endpoint_url,
                'secret_token' => $subscription->secret_token,
                'signature_mode' => $subscription->channel_type === 'feishu_bot' ? 'feishu_v1' : 'header_token',
                'template_id' => $subscription->notification_template_id,
                'credential_id' => $subscription->notification_channel_credential_id,
                'enabled' => true,
                'priority_order' => 1,
                'delivery_tier' => 'primary',
            ]]);
        }

        $credentialIds = $rawRoutes
            ->pluck('credential_id')
            ->filter()
            ->push($subscription->notification_channel_credential_id)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        $templateIds = $rawRoutes
            ->pluck('template_id')
            ->filter()
            ->push($subscription->notification_template_id)
            ->map(fn ($value): int => (int) $value)
            ->unique()
            ->values();

        $credentials = $credentialIds->isEmpty()
            ? collect()
            : NotificationChannelCredential::query()->whereIn('id', $credentialIds->all())->get()->keyBy('id');
        $templates = $templateIds->isEmpty()
            ? collect()
            : NotificationTemplate::query()->whereIn('id', $templateIds->all())->get()->keyBy('id');

        return $rawRoutes
            ->map(function (array $route) use ($subscription, $credentials, $templates): array {
                $credential = isset($route['credential_id']) ? $credentials->get((int) $route['credential_id']) : $subscription->notificationChannelCredential;
                $template = isset($route['template_id']) ? $templates->get((int) $route['template_id']) : $subscription->notificationTemplate;
                $channelType = (string) ($route['channel_type'] ?? $subscription->channel_type ?? 'webhook');
                $target = (string) ($route['target'] ?? $route['endpoint_url'] ?? $credential?->endpoint_url ?? $subscription->endpoint_url ?? '');

                return [
                    'route_key' => (string) ($route['route_key'] ?? Str::slug((string) ($route['label'] ?? $channelType ?? 'route'), '_')),
                    'label' => (string) ($route['label'] ?? $channelType ?? 'route'),
                    'channel_type' => $channelType,
                    'target' => $target,
                    'secret_token' => $route['secret_token'] ?? $credential?->secret_token ?? $subscription->secret_token,
                    'signing_secret' => $route['signing_secret'] ?? $credential?->signing_secret,
                    'signature_mode' => (string) ($route['signature_mode'] ?? ($channelType === 'feishu_bot' ? 'feishu_v1' : 'header_token')),
                    'message_format' => $route['message_format'] ?? $template?->message_format,
                    'template_id' => $template?->id,
                    'template' => $template,
                    'credential_id' => $credential?->id,
                    'credential' => $credential,
                    'enabled' => (bool) ($route['enabled'] ?? true),
                    'priority_order' => (int) ($route['priority_order'] ?? 100),
                    'delivery_tier' => (string) ($route['delivery_tier'] ?? 'primary'),
                ];
            })
            ->filter(fn (array $route): bool => $route['target'] !== '')
            ->sortBy('priority_order')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dispatchRoute(array $route, array $payload): array
    {
        $channelType = (string) ($route['channel_type'] ?? 'webhook');
        $target = (string) ($route['target'] ?? '');
        $rendered = $this->renderNotification($route, $payload);

        try {
            return match ($channelType) {
                'wecom_bot' => $this->dispatchHttpRoute(
                    $target,
                    $this->wecomPayload($rendered),
                    null,
                    $route
                ),
                'dingtalk_bot' => $this->dispatchHttpRoute(
                    $target,
                    $this->dingtalkPayload($rendered),
                    null,
                    $route
                ),
                'feishu_bot' => $this->dispatchFeishuRoute($target, $rendered, $route),
                'email' => $this->dispatchEmailRoute($target, $rendered, $route),
                default => $this->dispatchHttpRoute(
                    $target,
                    $this->webhookPayload($payload, $rendered),
                    $route['secret_token'] ?? null,
                    $route
                ),
            };
        } catch (\Throwable $exception) {
            return [
                'route_key' => $route['route_key'] ?? $channelType,
                'channel_type' => $channelType,
                'target' => SensitiveValueMasker::maskUrl($target) ?? $target,
                'ok' => false,
                'status' => null,
                'body' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function dispatchHttpRoute(string $target, array $body, ?string $secretToken, array $route): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($secretToken) {
            $headers['X-Trading-Signal-Token'] = $secretToken;
        }

        if (($route['signature_mode'] ?? 'header_token') === 'hmac_sha256' && $secretToken) {
            $timestamp = (string) now()->timestamp;
            $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

            $headers['X-Trading-Signal-Timestamp'] = $timestamp;
            $headers['X-Trading-Signal-Signature'] = base64_encode(
                hash_hmac('sha256', $bodyJson, $timestamp."\n".$secretToken, true)
            );
        }

        $response = $this->http
            ->timeout(10)
            ->withHeaders($headers)
            ->post($target, $body);

        return [
            'route_key' => $route['route_key'] ?? ($route['channel_type'] ?? 'webhook'),
            'channel_type' => $route['channel_type'] ?? 'webhook',
            'target' => SensitiveValueMasker::maskUrl($target) ?? $target,
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function dispatchEmailRoute(string $target, array $rendered, array $route): array
    {
        $this->mail->to($target)->send(new SignalAlertMail(
            (string) ($rendered['subject'] ?? 'Trading Studio 通知'),
            (string) ($rendered['body'] ?? '')
        ));

        return [
            'route_key' => $route['route_key'] ?? 'email',
            'channel_type' => 'email',
            'target' => SensitiveValueMasker::maskSecret($target, 2, 8) ?? $target,
            'ok' => true,
            'status' => 200,
            'body' => 'email_sent',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function wecomPayload(array $rendered): array
    {
        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => (string) ($rendered['body'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dingtalkPayload(array $rendered): array
    {
        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'title' => (string) ($rendered['subject'] ?? 'Trading Studio 通知'),
                'text' => (string) ($rendered['body'] ?? ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notificationSubject(array $payload): string
    {
        return (string) ($this->renderer->render($payload)['subject'] ?? 'Trading Studio 通知');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notificationBody(array $payload): string
    {
        return (string) ($this->renderer->render($payload)['body'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function renderNotification(array $route, array $payload): array
    {
        $template = $route['template'] ?? null;

        return $this->renderer->render(
            $payload,
            $template instanceof NotificationTemplate ? $template : null
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rendered
     * @return array<string, mixed>
     */
    private function webhookPayload(array $payload, array $rendered): array
    {
        return array_merge($payload, [
            'notification' => [
                'subject' => $rendered['subject'] ?? null,
                'body' => $rendered['body'] ?? null,
                'message_format' => $rendered['message_format'] ?? 'markdown',
                'template_key' => $rendered['template_key'] ?? null,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $rendered
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function dispatchFeishuRoute(string $target, array $rendered, array $route): array
    {
        $body = $this->feishuPayload($rendered);
        $headers = ['Content-Type' => 'application/json'];

        if (($route['signature_mode'] ?? 'feishu_v1') === 'feishu_v1' && ! empty($route['signing_secret'])) {
            $timestamp = (string) now()->timestamp;
            $body['timestamp'] = $timestamp;
            $body['sign'] = base64_encode(
                hash_hmac('sha256', '', $timestamp."\n".(string) $route['signing_secret'], true)
            );
        }

        $response = $this->http
            ->timeout(10)
            ->withHeaders($headers)
            ->post($target, $body);

        return [
            'route_key' => $route['route_key'] ?? 'feishu_bot',
            'channel_type' => 'feishu_bot',
            'target' => SensitiveValueMasker::maskUrl($target) ?? $target,
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => mb_substr((string) $response->body(), 0, 2000),
        ];
    }

    /**
     * @param  array<string, mixed>  $rendered
     * @return array<string, mixed>
     */
    private function feishuPayload(array $rendered): array
    {
        $subject = (string) ($rendered['subject'] ?? 'Trading Studio 通知');
        $body = (string) ($rendered['body'] ?? '');
        $messageFormat = (string) ($rendered['message_format'] ?? 'post');

        if ($messageFormat === 'text') {
            return [
                'msg_type' => 'text',
                'content' => [
                    'text' => $subject."\n".$body,
                ],
            ];
        }

        return [
            'msg_type' => 'post',
            'content' => [
                'post' => [
                    'zh_cn' => [
                        'title' => $subject,
                        'content' => collect(explode("\n", $body))
                            ->filter(fn (string $line): bool => trim($line) !== '')
                            ->map(fn (string $line): array => [[
                                'tag' => 'text',
                                'text' => $line,
                            ]])
                            ->values()
                            ->all(),
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function systemPayload(string $title, string $summary): array
    {
        return [
            'signal' => [
                'title' => $title,
                'signal_type' => 'system_notification',
                'direction' => 'neutral',
                'signal_score' => 100,
                'published_at' => now()->toAtomString(),
            ],
            'security' => [
                'symbol' => 'SYSTEM',
                'name' => 'Trading Studio',
            ],
            'chain' => [
                'chain_type' => 'system_ops',
                'topic' => 'notification_verify',
            ],
            'batch_signals' => [[
                'id' => 0,
                'title' => $title,
                'signal_type' => 'system_notification',
                'direction' => 'neutral',
                'signal_score' => 100,
                'symbol' => 'SYSTEM',
                'published_at' => now()->toAtomString(),
            ]],
            'meta' => [
                'batch_count' => 1,
                'source' => 'trading-studio',
                'delivered_at' => now()->toAtomString(),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signalSummary(TradingSignal $signal): array
    {
        return [
            'id' => $signal->id,
            'title' => $signal->title,
            'signal_type' => $signal->signal_type,
            'direction' => $signal->direction,
            'signal_score' => (float) $signal->signal_score,
            'symbol' => $signal->primarySecurity?->symbol,
            'published_at' => optional($signal->published_at)?->toAtomString(),
        ];
    }

    private function mergeGroupKey(TradingSignal $signal): string
    {
        return implode('|', [
            (string) ($signal->primarySecurity?->symbol ?? 'unknown'),
            (string) $signal->signal_type,
            (string) $signal->direction,
        ]);
    }

    private function markRetry(SignalDelivery $delivery, ?int $statusCode, string $body): void
    {
        $attempts = (int) $delivery->attempts;
        $nextRetry = $attempts >= 5 ? null : Carbon::now()->addMinutes(min(2 ** max($attempts - 1, 0), 60));

        $delivery->update([
            'delivery_status' => $attempts >= 5 ? 'failed' : 'retrying',
            'response_status' => $statusCode,
            'response_body' => mb_substr($body, 0, 2000),
            'next_retry_at' => $nextRetry,
            'delivered_at' => null,
        ]);
    }

    private function resetDeliveryForRetry(SignalDelivery $delivery): void
    {
        $delivery->update([
            'delivery_status' => 'queued',
            'next_retry_at' => null,
            'last_attempted_at' => null,
            'delivered_at' => null,
        ]);
    }
}

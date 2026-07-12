<?php

namespace App\Services;

use App\Models\SignalDelivery;
use App\Models\SignalSubscription;
use App\Models\TradingSignal;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SignalDeliveryService
{
    public function __construct(
        private readonly HttpFactory $http
    ) {
    }

    public function enqueuePendingDeliveries(): int
    {
        $signals = TradingSignal::query()
            ->with(['eventChain', 'primarySecurity'])
            ->where('status', 'active')
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->get();

        $subscriptions = SignalSubscription::query()
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

                SignalDelivery::query()->create([
                    'trading_signal_id' => $signal->id,
                    'signal_subscription_id' => $subscription->id,
                    'delivery_channel' => $subscription->channel_type,
                    'delivery_status' => 'queued',
                    'payload' => $this->payload($signal),
                ]);
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
            ->with(['signal.eventChain', 'signal.primarySecurity', 'subscription'])
            ->whereIn('delivery_status', ['queued', 'retrying'])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('signal_subscriptions.priority_order')
            ->orderByDesc('trading_signals.signal_score')
            ->orderBy('signal_deliveries.id')
            ->limit($limit)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($deliveries as $delivery) {
            $subscription = $delivery->subscription;
            $signal = $delivery->signal;
            if ($subscription === null || $signal === null || ! $subscription->enabled) {
                $delivery->update([
                    'delivery_status' => 'skipped',
                    'last_attempted_at' => now(),
                ]);
                continue;
            }

            $payload = $delivery->payload ?: $this->payload($signal);
            $headers = ['Content-Type' => 'application/json'];
            if ($subscription->secret_token) {
                $headers['X-Trading-Signal-Token'] = $subscription->secret_token;
            }

            $delivery->update([
                'delivery_status' => 'sending',
                'attempts' => $delivery->attempts + 1,
                'last_attempted_at' => now(),
            ]);

            try {
                $response = $this->http
                    ->timeout(10)
                    ->withHeaders($headers)
                    ->post($subscription->endpoint_url, $payload);

                if ($response->successful()) {
                    DB::transaction(function () use ($delivery, $subscription, $response): void {
                        $delivery->update([
                            'delivery_status' => 'success',
                            'response_status' => $response->status(),
                            'response_body' => mb_substr((string) $response->body(), 0, 2000),
                            'delivered_at' => now(),
                            'next_retry_at' => null,
                        ]);
                        $subscription->update(['last_notified_at' => now()]);
                    });
                    $sent++;
                    continue;
                }

                $this->markRetry($delivery, $response->status(), (string) $response->body());
                $failed++;
            } catch (\Throwable $exception) {
                $this->markRetry($delivery, null, $exception->getMessage());
                $failed++;
            }
        }

        return [
            'queued' => $deliveries->count(),
            'sent' => $sent,
            'failed' => $failed,
        ];
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
     * @return array<string, mixed>
     */
    public function payload(TradingSignal $signal): array
    {
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
            'meta' => [
                'delivered_at' => now()->toAtomString(),
                'source' => 'trading-studio',
            ],
        ];
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
        ]);
    }
}

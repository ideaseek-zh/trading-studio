<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SignalDeliveryResource;
use App\Models\SignalDelivery;
use App\Services\SignalDeliveryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SignalDeliveryController extends Controller
{
    public function __construct(
        private readonly SignalDeliveryService $deliveryService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $deliveries = SignalDelivery::query()
            ->with(['signal.primarySecurity', 'signal.latestEvent', 'subscription']);

        $this->applyFilters($deliveries, $request);

        $paginated = $deliveries
            ->orderByRaw($this->deliveryStatusOrder())
            ->orderByDesc('last_attempted_at')
            ->orderByDesc('created_at')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => SignalDeliveryResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'summary' => $this->summary($request),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function show(SignalDelivery $delivery): JsonResponse
    {
        $delivery->load(['signal.primarySecurity', 'signal.latestEvent', 'subscription']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new SignalDeliveryResource($delivery),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function retry(Request $request, SignalDelivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'dispatch_now' => ['nullable', 'boolean'],
        ]);

        $result = $this->deliveryService->retryDelivery(
            $delivery,
            $validated['dispatch_now'] ?? true
        );

        /** @var SignalDelivery $refreshed */
        $refreshed = $result['delivery'];
        $refreshed->load(['signal.primarySecurity', 'signal.latestEvent', 'subscription']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'dispatch_result' => $result['dispatch_result'],
                'delivery' => new SignalDeliveryResource($refreshed),
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->string('deliveryStatus')->isNotEmpty()) {
            $query->where('delivery_status', $request->string('deliveryStatus')->toString());
        }

        if ($request->string('channelType')->isNotEmpty()) {
            $query->where('delivery_channel', $request->string('channelType')->toString());
        }

        if ($request->string('subscriberKey')->isNotEmpty()) {
            $subscriberKey = $request->string('subscriberKey')->toString();
            $query->whereHas('subscription', fn (Builder $subscriptionQuery) => $subscriptionQuery
                ->where('subscriber_key', $subscriberKey));
        }

        if ($request->string('securitySymbol')->isNotEmpty()) {
            $symbol = $request->string('securitySymbol')->toString();
            $query->whereHas('signal.primarySecurity', fn (Builder $securityQuery) => $securityQuery
                ->where('symbol', $symbol));
        }

        if ($request->string('signalType')->isNotEmpty()) {
            $signalType = $request->string('signalType')->toString();
            $query->whereHas('signal', fn (Builder $signalQuery) => $signalQuery
                ->where('signal_type', $signalType));
        }

        if ($request->boolean('needsRetry')) {
            $query->whereIn('delivery_status', ['failed', 'retrying', 'suppressed'])
                ->where(function (Builder $retryQuery): void {
                    $retryQuery->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                });
        }
    }

    private function summary(Request $request): array
    {
        $rows = SignalDelivery::query()
            ->with(['subscription', 'signal.primarySecurity']);

        $this->applyFilters($rows, $request);

        $items = $rows->get();

        return [
            'total' => $items->count(),
            'queued' => $items->where('delivery_status', 'queued')->count(),
            'retrying' => $items->where('delivery_status', 'retrying')->count(),
            'failed' => $items->where('delivery_status', 'failed')->count(),
            'success' => $items->where('delivery_status', 'success')->count(),
            'partial_success' => $items->where('delivery_status', 'partial_success')->count(),
            'suppressed' => $items->where('delivery_status', 'suppressed')->count(),
            'merged' => $items->where('delivery_status', 'merged')->count(),
            'skipped' => $items->where('delivery_status', 'skipped')->count(),
            'due_retry' => $items->filter(fn (SignalDelivery $delivery): bool => in_array($delivery->delivery_status, ['failed', 'retrying', 'suppressed'], true)
                && ($delivery->next_retry_at === null || $delivery->next_retry_at->lte(now())))->count(),
        ];
    }

    private function deliveryStatusOrder(): string
    {
        return "CASE delivery_status
            WHEN 'retrying' THEN 1
            WHEN 'failed' THEN 2
            WHEN 'suppressed' THEN 3
            WHEN 'queued' THEN 4
            WHEN 'sending' THEN 5
            WHEN 'partial_success' THEN 6
            WHEN 'success' THEN 7
            WHEN 'merged' THEN 8
            WHEN 'skipped' THEN 9
            ELSE 99
        END";
    }
}

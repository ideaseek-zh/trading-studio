<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\SignalSubscriptionResource;
use App\Models\SignalSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class SignalSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $subscriptions = SignalSubscription::query()
            ->withCount('deliveries')
            ->when(
                $request->string('subscriberKey')->isNotEmpty(),
                fn ($query) => $query->where('subscriber_key', $request->string('subscriberKey')->toString())
            )
            ->when(
                $request->string('priorityLevel')->isNotEmpty(),
                fn ($query) => $query->where('priority_level', $request->string('priorityLevel')->toString())
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
        $validated = $request->validate([
            'subscriber_key' => ['required', 'string', 'max:128'],
            'subscriber_name' => ['nullable', 'string', 'max:128'],
            'channel_type' => ['required', 'string', Rule::in(['webhook'])],
            'priority_level' => ['nullable', 'string', Rule::in(['critical', 'high', 'normal', 'low'])],
            'priority_order' => ['nullable', 'integer', 'between:1,9999'],
            'endpoint_url' => ['required', 'url', 'max:1024'],
            'secret_token' => ['nullable', 'string', 'max:128'],
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
        ]);

        $subscription = SignalSubscription::query()->create([
            'subscriber_key' => $validated['subscriber_key'],
            'subscriber_name' => $validated['subscriber_name'] ?? null,
            'channel_type' => $validated['channel_type'],
            'priority_level' => $validated['priority_level'] ?? 'normal',
            'priority_order' => $validated['priority_order'] ?? 100,
            'endpoint_url' => $validated['endpoint_url'],
            'secret_token' => $validated['secret_token'] ?? null,
            'min_signal_score' => $validated['min_signal_score'] ?? 60,
            'enabled' => $validated['enabled'] ?? true,
            'filters' => $validated['filters'] ?? [],
        ]);

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
        $subscription->loadCount('deliveries');

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new SignalSubscriptionResource($subscription),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function update(Request $request, SignalSubscription $subscription): JsonResponse
    {
        $validated = $request->validate([
            'subscriber_name' => ['nullable', 'string', 'max:128'],
            'priority_level' => ['nullable', 'string', Rule::in(['critical', 'high', 'normal', 'low'])],
            'priority_order' => ['nullable', 'integer', 'between:1,9999'],
            'endpoint_url' => ['sometimes', 'url', 'max:1024'],
            'secret_token' => ['nullable', 'string', 'max:128'],
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
        ]);

        $subscription->fill($validated);
        $subscription->save();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new SignalSubscriptionResource($subscription),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }
}

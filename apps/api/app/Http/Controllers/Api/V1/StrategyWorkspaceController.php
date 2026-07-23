<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StrategyWorkspaceItemResource;
use App\Http\Resources\StrategyWorkspaceResource;
use App\Models\Security;
use App\Models\StrategyWorkspace;
use App\Models\StrategyWorkspaceItem;
use App\Services\StrategyRecommendationService;
use App\Services\StrategyWorkspaceMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StrategyWorkspaceController extends Controller
{
    public function __construct(
        private readonly StrategyWorkspaceMonitorService $monitorService,
        private readonly StrategyRecommendationService $recommendationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $workspaces = StrategyWorkspace::query()
            ->with(['defaultSignalSubscription'])
            ->withCount('items')
            ->when(
                $request->string('ownerKey')->isNotEmpty(),
                fn ($query) => $query->where('owner_key', $request->string('ownerKey')->toString())
            )
            ->when(
                $request->string('workspaceType')->isNotEmpty(),
                fn ($query) => $query->where('workspace_type', $request->string('workspaceType')->toString())
            )
            ->when(
                $request->has('enabled'),
                fn ($query) => $query->where('enabled', $request->boolean('enabled'))
            )
            ->orderByDesc('enabled')
            ->orderByDesc('updated_at')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => StrategyWorkspaceResource::collection($workspaces->items()),
            'meta' => [
                'current_page' => $workspaces->currentPage(),
                'last_page' => $workspaces->lastPage(),
                'per_page' => $workspaces->perPage(),
                'total' => $workspaces->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateWorkspace($request, false);

        $workspace = StrategyWorkspace::query()->create(array_merge($validated, [
            'workspace_key' => $validated['workspace_key'] ?? Str::slug($validated['name'].' '.Str::random(6), '_'),
        ]));
        $workspace->load(['defaultSignalSubscription'])->loadCount('items');

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new StrategyWorkspaceResource($workspace),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], 201);
    }

    public function show(StrategyWorkspace $workspace): JsonResponse
    {
        $workspace->load(['defaultSignalSubscription', 'items.security'])->loadCount('items');

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new StrategyWorkspaceResource($workspace),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function update(Request $request, StrategyWorkspace $workspace): JsonResponse
    {
        $validated = $this->validateWorkspace($request, true, $workspace);
        $workspace->fill($validated);
        $workspace->save();
        $workspace->load(['defaultSignalSubscription'])->loadCount('items');

        if ($request->has('default_signal_subscription_id')) {
            $this->syncSubscriptionSymbols($workspace);
        }

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new StrategyWorkspaceResource($workspace),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function destroy(StrategyWorkspace $workspace): JsonResponse
    {
        $workspace->delete();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'deleted' => true,
                'id' => $workspace->id,
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function overview(StrategyWorkspace $workspace): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->monitorService->overview($workspace),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function recommendations(Request $request, ?StrategyWorkspace $workspace = null): JsonResponse
    {
        $limit = $request->integer('limit', 20);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->recommendationService->recommendations($workspace, max(1, min($limit, 50))),
            'meta' => [
                'workspace_id' => $workspace?->id,
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function storeItem(Request $request, StrategyWorkspace $workspace): JsonResponse
    {
        $validated = $this->validateItem($request, false);
        $security = $this->resolveSecurity($validated);

        $item = StrategyWorkspaceItem::query()->updateOrCreate(
            [
                'strategy_workspace_id' => $workspace->id,
                'security_id' => $security->id,
            ],
            array_merge($validated, [
                'security_id' => $security->id,
                'strategy_workspace_id' => $workspace->id,
            ])
        );
        $item->load('security');
        $this->syncSubscriptionSymbols($workspace);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new StrategyWorkspaceItemResource($item),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], $item->wasRecentlyCreated ? 201 : 200);
    }

    public function updateItem(Request $request, StrategyWorkspace $workspace, StrategyWorkspaceItem $item): JsonResponse
    {
        abort_unless((int) $item->strategy_workspace_id === (int) $workspace->id, 404);

        $validated = $this->validateItem($request, true);
        if (isset($validated['security_id']) || isset($validated['canonical_symbol']) || isset($validated['symbol'])) {
            $validated['security_id'] = $this->resolveSecurity($validated)->id;
        }

        $item->fill($validated);
        $item->save();
        $item->load('security');
        $this->syncSubscriptionSymbols($workspace);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new StrategyWorkspaceItemResource($item),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function destroyItem(StrategyWorkspace $workspace, StrategyWorkspaceItem $item): JsonResponse
    {
        abort_unless((int) $item->strategy_workspace_id === (int) $workspace->id, 404);

        $item->delete();
        $this->syncSubscriptionSymbols($workspace);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'deleted' => true,
                'id' => $item->id,
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWorkspace(Request $request, bool $partial, ?StrategyWorkspace $workspace = null): array
    {
        return $request->validate([
            'workspace_key' => ['nullable', 'string', 'max:128', Rule::unique('strategy_workspaces', 'workspace_key')->ignore($workspace?->id)],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:128'],
            'owner_key' => ['nullable', 'string', 'max:128'],
            'workspace_type' => ['nullable', 'string', Rule::in(['watchlist', 'portfolio', 'theme', 'risk_watch'])],
            'risk_profile' => ['nullable', 'string', Rule::in(['conservative', 'balanced', 'aggressive'])],
            'base_currency' => ['nullable', 'string', 'max:8'],
            'default_signal_subscription_id' => ['nullable', 'integer', 'exists:signal_subscriptions,id'],
            'settings' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
            'last_reviewed_at' => ['nullable', 'date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateItem(Request $request, bool $partial): array
    {
        return $request->validate([
            'security_id' => ['nullable', 'integer', 'exists:securities,id'],
            'canonical_symbol' => ['nullable', 'string', 'max:64'],
            'symbol' => [$partial ? 'nullable' : 'required_without_all:security_id,canonical_symbol', 'string', 'max:32'],
            'item_type' => ['nullable', 'string', Rule::in(['watch', 'holding', 'candidate', 'hedge'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'paused', 'archived'])],
            'position_quantity' => ['nullable', 'numeric', 'min:0'],
            'average_cost' => ['nullable', 'numeric', 'min:0'],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'stop_loss_price' => ['nullable', 'numeric', 'min:0'],
            'alert_score_threshold' => ['nullable', 'numeric', 'between:0,100'],
            'position_weight_bps' => ['nullable', 'integer', 'between:0,10000'],
            'review_cadence' => ['nullable', 'string', Rule::in(['intraday', 'daily', 'weekly', 'event_driven'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'alert_preferences' => ['nullable', 'array'],
            'last_reviewed_at' => ['nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveSecurity(array $validated): Security
    {
        if (isset($validated['security_id'])) {
            return Security::query()->findOrFail((int) $validated['security_id']);
        }

        if (! empty($validated['canonical_symbol'])) {
            return Security::query()->where('canonical_symbol', (string) $validated['canonical_symbol'])->firstOrFail();
        }

        return Security::query()->where('symbol', (string) $validated['symbol'])->firstOrFail();
    }

    private function syncSubscriptionSymbols(StrategyWorkspace $workspace): void
    {
        $workspace->loadMissing(['defaultSignalSubscription', 'items.security']);
        $subscription = $workspace->defaultSignalSubscription;
        if ($subscription === null) {
            return;
        }

        $symbols = $workspace->items()
            ->where('status', 'active')
            ->with('security')
            ->get()
            ->map(fn (StrategyWorkspaceItem $item): ?string => $item->security?->symbol)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $filters = $subscription->filters ?? [];
        $filters['security_symbols'] = $symbols;

        $subscription->update([
            'filters' => $filters,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\MarketDailyBarResource;
use App\Http\Resources\MarketQuoteResource;
use App\Http\Resources\SecurityResource;
use App\Models\Security;
use App\Services\MarketDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SecurityController extends Controller
{
    public function __construct(
        private readonly MarketDataService $marketDataService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $securities = Security::query()
            ->when(
                $request->string('market')->isNotEmpty(),
                fn ($query) => $query->where('market', $request->string('market')->toString())
            )
            ->when(
                $request->string('security_type')->isNotEmpty(),
                fn ($query) => $query->where('security_type', $request->string('security_type')->toString())
            )
            ->orderBy('symbol')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => SecurityResource::collection($securities->items()),
            'meta' => [
                'current_page' => $securities->currentPage(),
                'last_page' => $securities->lastPage(),
                'per_page' => $securities->perPage(),
                'total' => $securities->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1'],
        ]);

        $query = trim((string) $request->input('q'));

        $securities = Security::query()
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('symbol', 'like', "%{$query}%")
                    ->orWhere('name', 'like', "%{$query}%")
                    ->orWhere('short_name', 'like', "%{$query}%")
                    ->orWhere('pinyin', 'like', "%{$query}%")
                    ->orWhere('canonical_symbol', 'like', "%{$query}%");
            })
            ->orderBy('symbol')
            ->limit(20)
            ->get();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => SecurityResource::collection($securities),
            'meta' => [
                'query' => $query,
                'count' => $securities->count(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function show(Security $security): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new SecurityResource($security),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function quote(Security $security): JsonResponse
    {
        $quote = $this->marketDataService->latestQuote($security);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $quote ? new MarketQuoteResource($quote) : null,
            'meta' => [
                'symbol' => $security->canonical_symbol,
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function dailyBars(Request $request, Security $security): JsonResponse
    {
        $adjust = $request->string('adjust')->toString() ?: 'none';

        $bars = $this->marketDataService->dailyBars(
            $security,
            $request->string('startDate')->toString() ?: null,
            $request->string('endDate')->toString() ?: null,
            $adjust
        );

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => MarketDailyBarResource::collection($bars),
            'meta' => [
                'count' => $bars->count(),
                'symbol' => $security->canonical_symbol,
                'adjust' => $adjust,
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }
}

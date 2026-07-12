<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\MarketDailyBarResource;
use App\Http\Resources\MarketIndexResource;
use App\Models\MarketIndex;
use App\Services\MarketDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class IndexController extends Controller
{
    public function __construct(
        private readonly MarketDataService $marketDataService
    ) {
    }

    public function index(): JsonResponse
    {
        $indices = $this->marketDataService->indexSnapshots();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => MarketIndexResource::collection($indices),
            'meta' => [
                'count' => $indices->count(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function dailyBars(Request $request, MarketIndex $index): JsonResponse
    {
        $bars = $this->marketDataService->indexDailyBars(
            $index,
            $request->string('startDate')->toString() ?: null,
            $request->string('endDate')->toString() ?: null,
        );

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => MarketDailyBarResource::collection($bars),
            'meta' => [
                'count' => $bars->count(),
                'code' => $index->code,
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }
}

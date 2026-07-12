<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\EventChainResource;
use App\Models\EventChain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EventChainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $chains = EventChain::query()
            ->with(['primarySecurity'])
            ->withCount('events')
            ->when(
                $request->string('chainType')->isNotEmpty(),
                fn ($query) => $query->where('chain_type', $request->string('chainType')->toString())
            )
            ->when(
                $request->string('status')->isNotEmpty(),
                fn ($query) => $query->where('status', $request->string('status')->toString())
            )
            ->when(
                $request->string('securitySymbol')->isNotEmpty(),
                fn ($query) => $query->whereHas(
                    'primarySecurity',
                    fn ($securityQuery) => $securityQuery->where('symbol', $request->string('securitySymbol')->toString())
                )
            )
            ->orderByDesc('latest_occurred_at')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => EventChainResource::collection($chains->items()),
            'meta' => [
                'current_page' => $chains->currentPage(),
                'last_page' => $chains->lastPage(),
                'per_page' => $chains->perPage(),
                'total' => $chains->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function show(EventChain $chain): JsonResponse
    {
        $chain->load([
            'primarySecurity',
            'events' => fn ($query) => $query
                ->where('status', '!=', 'merged')
                ->with(['primarySecurity', 'articles'])
                ->orderBy('timeline_order')
                ->orderBy('occurred_at'),
        ]);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new EventChainResource($chain),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }
}

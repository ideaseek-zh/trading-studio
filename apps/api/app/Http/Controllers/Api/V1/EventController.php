<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\MarketEventResource;
use App\Models\MarketEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = MarketEvent::query()
            ->with(['primarySecurity', 'articles', 'eventChain'])
            ->when(
                $request->string('importance')->isNotEmpty(),
                fn ($query) => $query->where('importance_level', $request->string('importance')->toString())
            )
            ->when(
                $request->string('eventType')->isNotEmpty(),
                fn ($query) => $query->where('event_type', $request->string('eventType')->toString())
            )
            ->when(
                $request->string('securitySymbol')->isNotEmpty(),
                fn ($query) => $query->whereHas(
                    'primarySecurity',
                    fn ($securityQuery) => $securityQuery->where('symbol', $request->string('securitySymbol')->toString())
                )
            )
            ->when(
                $request->string('chainType')->isNotEmpty(),
                fn ($query) => $query->whereHas(
                    'eventChain',
                    fn ($chainQuery) => $chainQuery->where('chain_type', $request->string('chainType')->toString())
                )
            )
            ->orderByDesc('occurred_at')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => MarketEventResource::collection($events->items()),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function show(MarketEvent $event): JsonResponse
    {
        $event->load(['primarySecurity', 'articles', 'eventChain']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new MarketEventResource($event),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }
}

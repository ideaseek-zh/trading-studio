<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class NewsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $articles = NewsArticle::query()
            ->with(['source', 'securities'])
            ->when(
                $request->string('category')->isNotEmpty(),
                fn ($query) => $query->where('category', $request->string('category')->toString())
            )
            ->when(
                $request->string('qualityStatus')->isNotEmpty(),
                fn ($query) => $query->where('quality_status', $request->string('qualityStatus')->toString())
            )
            ->when(
                $request->string('importance')->isNotEmpty(),
                fn ($query) => $query->where('importance_level', $request->string('importance')->toString())
            )
            ->when(
                $request->string('sourceKey')->isNotEmpty(),
                fn ($query) => $query->whereHas(
                    'source',
                    fn ($sourceQuery) => $sourceQuery->where('source_key', $request->string('sourceKey')->toString())
                )
            )
            ->when(
                $request->string('securitySymbol')->isNotEmpty(),
                fn ($query) => $query->whereHas(
                    'securities',
                    fn ($securityQuery) => $securityQuery->where('symbol', $request->string('securitySymbol')->toString())
                )
            )
            ->orderByDesc('published_at')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => NewsArticleResource::collection($articles->items()),
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'per_page' => $articles->perPage(),
                'total' => $articles->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function show(NewsArticle $article): JsonResponse
    {
        $article->load(['source', 'content', 'securities', 'events']);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new NewsArticleResource($article),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }
}

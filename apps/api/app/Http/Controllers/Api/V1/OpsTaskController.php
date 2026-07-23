<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\OpsTaskRunResource;
use App\Models\OpsTaskRun;
use App\Services\OpsTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

class OpsTaskController extends Controller
{
    public function __construct(
        private readonly OpsTaskService $taskService
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => $this->taskService->catalog(),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function runs(Request $request): JsonResponse
    {
        $runs = OpsTaskRun::query()
            ->when(
                $request->string('taskKey')->isNotEmpty(),
                fn ($query) => $query->where('task_key', $request->string('taskKey')->toString())
            )
            ->when(
                $request->string('status')->isNotEmpty(),
                fn ($query) => $query->where('status', $request->string('status')->toString())
            )
            ->orderByDesc('created_at')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => OpsTaskRunResource::collection($runs->items()),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'per_page' => $runs->perPage(),
                'total' => $runs->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function run(Request $request, string $taskKey): JsonResponse
    {
        $validated = $request->validate([
            'symbols' => ['nullable'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'index_code' => ['nullable', 'string', 'max:32'],
            'triggered_by' => ['nullable', 'string', 'max:128'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        try {
            $run = $this->taskService->run(
                $taskKey,
                collect($validated)->except(['triggered_by', 'confirm'])->all(),
                $validated['triggered_by'] ?? 'web-console'
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'code' => 404,
                'message' => $exception->getMessage(),
                'data' => null,
                'meta' => [
                    'timestamp' => now()->toAtomString(),
                ],
            ], 404);
        }

        return response()->json([
            'code' => $run->status === 'failed' ? 1 : 0,
            'message' => $run->status === 'failed' ? 'failed' : 'success',
            'data' => new OpsTaskRunResource($run),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], $run->status === 'failed' ? 500 : 201);
    }
}

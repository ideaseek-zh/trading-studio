<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
                'services' => [
                    'api' => 'up',
                    'database' => config('database.default'),
                    'cache' => config('cache.default'),
                ],
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }
}

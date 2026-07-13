<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\NotificationChannelCredentialResource;
use App\Models\NotificationChannelCredential;
use App\Models\NotificationTemplate;
use App\Services\SignalDeliveryService;
use App\Support\SensitiveValueMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class NotificationChannelCredentialController extends Controller
{
    public function __construct(
        private readonly SignalDeliveryService $deliveryService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $credentials = NotificationChannelCredential::query()
            ->when(
                $request->string('channelType')->isNotEmpty(),
                fn ($query) => $query->where('channel_type', $request->string('channelType')->toString())
            )
            ->when(
                $request->has('enabled'),
                fn ($query) => $query->where('enabled', $request->boolean('enabled'))
            )
            ->orderByDesc('enabled')
            ->orderBy('channel_type')
            ->orderBy('credential_key')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => NotificationChannelCredentialResource::collection($credentials->items()),
            'meta' => [
                'current_page' => $credentials->currentPage(),
                'last_page' => $credentials->lastPage(),
                'per_page' => $credentials->perPage(),
                'total' => $credentials->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCredential($request, false, null);

        $credential = NotificationChannelCredential::query()->create($validated);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new NotificationChannelCredentialResource($credential),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], 201);
    }

    public function show(NotificationChannelCredential $credential): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new NotificationChannelCredentialResource($credential),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function update(Request $request, NotificationChannelCredential $credential): JsonResponse
    {
        $validated = $this->validateCredential($request, true, $credential);
        $credential->fill($validated);
        $credential->save();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new NotificationChannelCredentialResource($credential),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function destroy(NotificationChannelCredential $credential): JsonResponse
    {
        $credential->delete();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'deleted' => true,
                'id' => $credential->id,
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function verify(Request $request, NotificationChannelCredential $credential): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:notification_templates,id'],
        ]);

        $template = isset($validated['template_id'])
            ? NotificationTemplate::query()->find((int) $validated['template_id'])
            : null;

        $result = $this->deliveryService->testCredentialRoute($credential, $template);

        if ($result['ok']) {
            $credential->forceFill([
                'last_verified_at' => now(),
            ])->save();
        }

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'credential_id' => $credential->id,
                'ok' => $result['ok'],
                'result' => $result,
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], $result['ok'] ? 200 : 502);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCredential(Request $request, bool $partial, ?NotificationChannelCredential $credential): array
    {
        $validated = $request->validate([
            'credential_key' => [$partial ? 'sometimes' : 'required', 'string', 'max:128', Rule::unique('notification_channel_credentials', 'credential_key')->ignore($credential?->id)],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:128'],
            'channel_type' => [$partial ? 'sometimes' : 'required', 'string', Rule::in(['webhook', 'wecom_bot', 'dingtalk_bot', 'feishu_bot', 'email'])],
            'endpoint_url' => ['nullable', 'string', 'max:4000'],
            'secret_token' => ['nullable', 'string', 'max:4000'],
            'signing_secret' => ['nullable', 'string', 'max:4000'],
            'config' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        foreach (['endpoint_url', 'secret_token', 'signing_secret'] as $field) {
            if (array_key_exists($field, $validated) && SensitiveValueMasker::looksMasked($validated[$field])) {
                unset($validated[$field]);
            }
        }

        return $validated;
    }
}

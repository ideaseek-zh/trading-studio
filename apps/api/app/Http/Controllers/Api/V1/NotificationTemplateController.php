<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class NotificationTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = NotificationTemplate::query()
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
            ->orderBy('template_key')
            ->paginate($request->integer('pageSize', 20));

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => NotificationTemplateResource::collection($templates->items()),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateTemplate($request, false, null);

        $template = NotificationTemplate::query()->create($validated);

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new NotificationTemplateResource($template),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ], 201);
    }

    public function show(NotificationTemplate $template): JsonResponse
    {
        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new NotificationTemplateResource($template),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function update(Request $request, NotificationTemplate $template): JsonResponse
    {
        $validated = $this->validateTemplate($request, true, $template);
        $template->fill($validated);
        $template->save();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => new NotificationTemplateResource($template),
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    public function destroy(NotificationTemplate $template): JsonResponse
    {
        $template->delete();

        return response()->json([
            'code' => 0,
            'message' => 'success',
            'data' => [
                'deleted' => true,
                'id' => $template->id,
            ],
            'meta' => [
                'timestamp' => now()->toAtomString(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request, bool $partial, ?NotificationTemplate $template): array
    {
        return $request->validate([
            'template_key' => [$partial ? 'sometimes' : 'required', 'string', 'max:128', Rule::unique('notification_templates', 'template_key')->ignore($template?->id)],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:128'],
            'channel_type' => [$partial ? 'sometimes' : 'required', 'string', Rule::in(['webhook', 'wecom_bot', 'dingtalk_bot', 'feishu_bot', 'email'])],
            'message_format' => ['nullable', 'string', Rule::in(['text', 'markdown', 'post'])],
            'subject_template' => ['nullable', 'string'],
            'body_template' => [$partial ? 'sometimes' : 'required', 'string'],
            'config' => ['nullable', 'array'],
            'enabled' => ['nullable', 'boolean'],
        ]);
    }
}

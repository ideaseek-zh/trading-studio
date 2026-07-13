<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_key' => $this->template_key,
            'name' => $this->name,
            'channel_type' => $this->channel_type,
            'message_format' => $this->message_format,
            'subject_template' => $this->subject_template,
            'body_template' => $this->body_template,
            'config' => $this->config,
            'enabled' => $this->enabled,
            'created_at' => optional($this->created_at)?->toAtomString(),
            'updated_at' => optional($this->updated_at)?->toAtomString(),
        ];
    }
}

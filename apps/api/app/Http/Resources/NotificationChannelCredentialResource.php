<?php

namespace App\Http\Resources;

use App\Support\SensitiveValueMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationChannelCredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credential_key' => $this->credential_key,
            'name' => $this->name,
            'channel_type' => $this->channel_type,
            'endpoint_url' => SensitiveValueMasker::maskUrl($this->endpoint_url),
            'endpoint_url_masked' => SensitiveValueMasker::maskUrl($this->endpoint_url),
            'endpoint_configured' => filled($this->endpoint_url),
            'secret_token_masked' => SensitiveValueMasker::maskSecret($this->secret_token),
            'secret_token_configured' => filled($this->secret_token),
            'signing_secret_masked' => SensitiveValueMasker::maskSecret($this->signing_secret),
            'signing_secret_configured' => filled($this->signing_secret),
            'config' => $this->config,
            'enabled' => $this->enabled,
            'last_verified_at' => optional($this->last_verified_at)?->toAtomString(),
            'created_at' => optional($this->created_at)?->toAtomString(),
            'updated_at' => optional($this->updated_at)?->toAtomString(),
        ];
    }
}

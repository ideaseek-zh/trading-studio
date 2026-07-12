<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SecurityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'canonical_symbol' => $this->canonical_symbol,
            'symbol' => $this->symbol,
            'exchange' => $this->exchange,
            'market' => $this->market,
            'security_type' => $this->security_type,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'pinyin' => $this->pinyin,
            'status' => $this->status,
            'currency' => $this->currency,
            'list_date' => optional($this->list_date)->toDateString(),
            'delist_date' => optional($this->delist_date)->toDateString(),
            'metadata' => $this->metadata,
        ];
    }
}

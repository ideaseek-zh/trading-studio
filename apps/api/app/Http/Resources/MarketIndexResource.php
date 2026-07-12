<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketIndexResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'exchange' => $this->exchange,
            'market' => $this->market,
            'index_type' => $this->index_type,
            'status' => $this->status,
            'quote_time' => optional($this->quote_time)?->toAtomString(),
            'last_price' => $this->last_price,
            'change_amount' => $this->change_amount,
            'pct_change' => $this->pct_change,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'pre_close' => $this->pre_close,
            'volume' => $this->volume,
            'amount' => $this->amount,
            'source_timestamp' => optional($this->source_timestamp)?->toAtomString(),
        ];
    }
}

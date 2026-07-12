<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quote_time' => optional($this->quote_time)?->toAtomString(),
            'last_price' => $this->last_price,
            'pre_close' => $this->pre_close,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'volume' => $this->volume,
            'amount' => $this->amount,
            'turnover_rate' => $this->turnover_rate,
            'pct_change' => $this->pct_change,
            'provider' => $this->provider,
            'source_timestamp' => optional($this->source_timestamp)?->toAtomString(),
        ];
    }
}

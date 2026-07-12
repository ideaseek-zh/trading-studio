<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketDailyBarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'trade_date' => optional($this->trade_date)->toDateString(),
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'pre_close' => $this->pre_close,
            'volume' => $this->volume,
            'amount' => $this->amount,
            'turnover_rate' => $this->turnover_rate,
            'pct_change' => $this->pct_change,
            'adjust_type' => $this->adjust_type,
            'provider' => $this->provider,
        ];
    }
}

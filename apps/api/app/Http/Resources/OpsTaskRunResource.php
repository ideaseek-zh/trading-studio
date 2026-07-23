<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpsTaskRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_key' => $this->task_key,
            'task_name' => $this->task_name,
            'status' => $this->status,
            'triggered_by' => $this->triggered_by,
            'input' => $this->input,
            'result' => $this->result,
            'output' => $this->output,
            'error' => $this->error,
            'started_at' => optional($this->started_at)?->toAtomString(),
            'finished_at' => optional($this->finished_at)?->toAtomString(),
            'duration_ms' => $this->duration_ms,
            'created_at' => optional($this->created_at)?->toAtomString(),
        ];
    }
}

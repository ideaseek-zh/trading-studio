<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpsTaskRun extends Model
{
    protected $fillable = [
        'task_key',
        'task_name',
        'status',
        'triggered_by',
        'input',
        'result',
        'output',
        'error',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
    ];
}

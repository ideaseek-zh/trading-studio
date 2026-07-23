<?php

namespace App\Console\Commands;

use App\Services\OpsTaskService;
use Illuminate\Console\Command;

class RunOpsTaskCommand extends Command
{
    protected $signature = 'ops:run-task
        {taskKey=one_click_radar_refresh : Ops task key}
        {--symbols= : Comma separated stock symbols}
        {--start= : Start date in YYYY-MM-DD}
        {--end= : End date in YYYY-MM-DD}
        {--limit= : Max rows fetched per source scope}
        {--index-code= : Index code such as sh000001}
        {--triggered-by=scheduler : Task trigger source}';

    protected $description = 'Run an ops task and persist its execution result.';

    public function __construct(
        private readonly OpsTaskService $taskService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $input = collect([
            'symbols' => $this->option('symbols') ?: null,
            'start_date' => $this->option('start') ?: null,
            'end_date' => $this->option('end') ?: null,
            'limit' => $this->option('limit') ?: null,
            'index_code' => $this->option('index-code') ?: null,
        ])->filter(fn ($value): bool => $value !== null && $value !== '')->all();

        $run = $this->taskService->run(
            (string) $this->argument('taskKey'),
            $input,
            (string) $this->option('triggered-by')
        );

        $this->info("#{$run->id} {$run->task_name}: {$run->status}");

        if ($run->error) {
            $this->error($run->error);
        }

        if ($run->output) {
            $this->line($run->output);
        }

        return in_array($run->status, ['succeeded', 'partial_success'], true) ? self::SUCCESS : self::FAILURE;
    }
}

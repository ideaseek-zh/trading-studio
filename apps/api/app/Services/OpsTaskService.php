<?php

namespace App\Services;

use App\Models\OpsTaskRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class OpsTaskService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        $today = now('Asia/Shanghai');

        return [
            [
                'task_key' => 'bootstrap_market',
                'name' => '初始化股票与指数',
                'category' => 'market',
                'description' => '同步 A 股主数据和主要指数快照，适合第一次安装后执行。',
                'estimated_seconds' => 60,
                'defaults' => [],
            ],
            [
                'task_key' => 'refresh_quotes',
                'name' => '刷新自选行情快照',
                'category' => 'market',
                'description' => '按股票代码刷新最新行情快照，用于盘中盯盘。',
                'estimated_seconds' => 20,
                'defaults' => [
                    'symbols' => $this->defaultSymbols(),
                ],
            ],
            [
                'task_key' => 'refresh_daily_bars',
                'name' => '刷新日线与指数日线',
                'category' => 'market',
                'description' => '刷新重点股票日线和上证指数日线，用于信号评估与回测基线。',
                'estimated_seconds' => 60,
                'defaults' => [
                    'symbols' => ['300059', '000001'],
                    'index_code' => 'sh000001',
                    'start_date' => $today->copy()->subMonths(6)->toDateString(),
                    'end_date' => $today->toDateString(),
                ],
            ],
            [
                'task_key' => 'fetch_hot_news',
                'name' => '抓取热点新闻',
                'category' => 'news',
                'description' => '抓取全市场热点与快讯，并完成去重、质量校验、证券关联和事件入库。',
                'estimated_seconds' => 60,
                'defaults' => [
                    'limit' => 50,
                ],
            ],
            [
                'task_key' => 'fetch_stock_news',
                'name' => '抓取个股新闻与公告',
                'category' => 'news',
                'description' => '按股票代码抓取个股新闻、公告全文、附件和结构化事件。',
                'estimated_seconds' => 90,
                'defaults' => [
                    'symbols' => $this->defaultSymbols(),
                    'start_date' => $today->copy()->subDays(14)->toDateString(),
                    'end_date' => $today->toDateString(),
                    'limit' => 50,
                ],
            ],
            [
                'task_key' => 'rebuild_recommendations',
                'name' => '生成推荐关注股票',
                'category' => 'signal',
                'description' => '重建事件链、生成交易信号、刷新解释与表现评估，推荐池会随之更新。',
                'estimated_seconds' => 45,
                'defaults' => [],
            ],
            [
                'task_key' => 'one_click_radar_refresh',
                'name' => '一键刷新热点雷达',
                'category' => 'workflow',
                'description' => '串联行情、热点新闻、个股公告、事件链和信号生成，是日常打开系统后最推荐的按钮。',
                'estimated_seconds' => 180,
                'defaults' => [
                    'symbols' => $this->defaultSymbols(),
                    'start_date' => $today->copy()->subDays(14)->toDateString(),
                    'end_date' => $today->toDateString(),
                    'limit' => 50,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(string $taskKey, array $input = [], ?string $triggeredBy = null): OpsTaskRun
    {
        $task = collect($this->catalog())->firstWhere('task_key', $taskKey);
        if ($task === null) {
            throw new InvalidArgumentException("Unknown ops task [{$taskKey}].");
        }

        $startedAt = now();
        $run = OpsTaskRun::query()->create([
            'task_key' => $taskKey,
            'task_name' => $task['name'],
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'input' => $input,
            'started_at' => $startedAt,
        ]);

        $steps = [];
        $output = [];

        try {
            foreach ($this->stepsFor($taskKey, $input) as $step) {
                try {
                    $steps[] = $this->runStep($step, $output);
                } catch (Throwable $stepException) {
                    $steps[] = $this->failedStep($step, $stepException);
                    $output[] = sprintf(
                        "[%s] %s\nFAILED: %s",
                        $step['name'],
                        $step['command'],
                        $stepException->getMessage()
                    );

                    if (! ($step['continue_on_failure'] ?? false)) {
                        throw $stepException;
                    }
                }
            }

            $finishedAt = now();
            $hasFailedStep = collect($steps)->contains(fn (array $step): bool => $step['status'] === 'failed');
            $status = $hasFailedStep ? 'partial_success' : 'succeeded';
            $run->fill([
                'status' => $status,
                'result' => [
                    'steps' => $steps,
                    'summary' => $status === 'partial_success' ? "{$task['name']} 部分完成" : "{$task['name']} 已完成",
                ],
                'output' => implode("\n\n", $output),
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            ])->save();
        } catch (Throwable $exception) {
            $finishedAt = now();
            $run->fill([
                'status' => 'failed',
                'result' => [
                    'steps' => $steps,
                    'summary' => "{$task['name']} 执行失败",
                ],
                'output' => implode("\n\n", $output),
                'error' => $exception->getMessage(),
                'finished_at' => $finishedAt,
                'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            ])->save();
        }

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, array<string, mixed>>
     */
    private function stepsFor(string $taskKey, array $input): array
    {
        $symbols = $this->symbols($input);
        $limit = $this->positiveInt($input['limit'] ?? 50, 50);
        $today = now('Asia/Shanghai');
        $startDate = $this->dateValue($input['start_date'] ?? $today->copy()->subDays(14)->toDateString());
        $endDate = $this->dateValue($input['end_date'] ?? $today->toDateString());
        $indexCode = $this->stringValue($input['index_code'] ?? 'sh000001');

        return match ($taskKey) {
            'bootstrap_market' => [
                $this->step('同步股票主数据', 'market:sync-securities'),
                $this->step('同步主要指数快照', 'market:sync-indices'),
            ],
            'refresh_quotes' => [
                $this->step('刷新股票行情快照', 'market:sync-quotes', ['symbol' => $symbols]),
            ],
            'refresh_daily_bars' => [
                ...collect($symbols)->map(fn (string $symbol): array => $this->step(
                    "刷新 {$symbol} 日线",
                    'market:sync-daily-bars',
                    ['symbol' => $symbol, '--start' => $startDate, '--end' => $endDate, '--adjust' => 'none']
                ))->all(),
                $this->step('刷新指数日线', 'market:sync-index-daily-bars', ['code' => $indexCode, '--start' => $startDate, '--end' => $endDate]),
            ],
            'fetch_hot_news' => [
                $this->step('刷新新闻源配置', 'news:sync-sources'),
                $this->step('抓取全市场热点新闻', 'news:sync', ['--scope' => ['global', 'notice'], '--limit' => $limit]),
            ],
            'fetch_stock_news' => [
                $this->step('抓取个股新闻', 'news:sync', ['--scope' => ['stock'], '--symbols' => implode(',', $symbols), '--limit' => $limit]),
                $this->step('抓取个股公告', 'news:sync', [
                    '--scope' => ['disclosure'],
                    '--symbols' => implode(',', $symbols),
                    '--start' => $startDate,
                    '--end' => $endDate,
                    '--limit' => $limit,
                ]),
            ],
            'rebuild_recommendations' => [
                $this->step('重建事件链与时间线', 'news:rebuild-event-chains'),
                $this->step('生成交易信号', 'signals:rebuild'),
                $this->step('刷新信号解释与表现评估', 'signals:evaluate'),
            ],
            'one_click_radar_refresh' => [
                $this->step('刷新股票行情快照', 'market:sync-quotes', ['symbol' => $symbols], true),
                $this->step('刷新新闻源配置', 'news:sync-sources', [], true),
                $this->step('抓取全市场热点新闻', 'news:sync', ['--scope' => ['global', 'notice'], '--limit' => $limit], true),
                $this->step('抓取个股新闻', 'news:sync', ['--scope' => ['stock'], '--symbols' => implode(',', $symbols), '--limit' => $limit], true),
                $this->step('抓取个股公告', 'news:sync', [
                    '--scope' => ['disclosure'],
                    '--symbols' => implode(',', $symbols),
                    '--start' => $startDate,
                    '--end' => $endDate,
                    '--limit' => $limit,
                ], true),
                $this->step('重建事件链与时间线', 'news:rebuild-event-chains', [], true),
                $this->step('生成交易信号', 'signals:rebuild', [], true),
                $this->step('刷新信号解释与表现评估', 'signals:evaluate', [], true),
            ],
            default => throw new InvalidArgumentException("Unknown ops task [{$taskKey}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<int, string>  $output
     * @return array<string, mixed>
     */
    private function runStep(array $step, array &$output): array
    {
        $startedAt = now();
        $exitCode = Artisan::call($step['command'], $step['arguments']);
        $commandOutput = trim(Artisan::output());
        $finishedAt = now();

        $output[] = sprintf(
            "[%s] %s\n%s",
            $step['name'],
            $step['command'],
            $commandOutput !== '' ? $commandOutput : '(no output)'
        );

        if ($exitCode !== 0) {
            throw new InvalidArgumentException("Step [{$step['name']}] failed with exit code {$exitCode}.");
        }

        return [
            'name' => $step['name'],
            'command' => $step['command'],
            'arguments' => $step['arguments'],
            'status' => 'succeeded',
            'exit_code' => $exitCode,
            'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function step(string $name, string $command, array $arguments = [], bool $continueOnFailure = false): array
    {
        return [
            'name' => $name,
            'command' => $command,
            'arguments' => $arguments,
            'continue_on_failure' => $continueOnFailure,
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function failedStep(array $step, Throwable $exception): array
    {
        return [
            'name' => $step['name'],
            'command' => $step['command'],
            'arguments' => $step['arguments'],
            'status' => 'failed',
            'exit_code' => 1,
            'duration_ms' => 0,
            'error' => $exception->getMessage(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    private function symbols(array $input): array
    {
        $value = $input['symbols'] ?? $this->defaultSymbols();
        $symbols = is_array($value) ? $value : explode(',', (string) $value);

        return collect($symbols)
            ->map(fn ($symbol): string => Str::upper(trim((string) $symbol)))
            ->filter(fn (string $symbol): bool => preg_match('/^\d{6}$/', $symbol) === 1)
            ->unique()
            ->values()
            ->all() ?: $this->defaultSymbols();
    }

    /**
     * @return array<int, string>
     */
    private function defaultSymbols(): array
    {
        return ['300059', '000001', '002311', '300687', '601127'];
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $number = (int) $value;

        return $number > 0 ? min($number, 200) : $default;
    }

    private function dateValue(mixed $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : now('Asia/Shanghai')->toDateString();
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value) ?: 'sh000001';
    }
}

<?php

namespace Tests\Feature;

use App\Models\OpsTaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpsTaskCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_ops_tasks(): void
    {
        $response = $this->getJson('/api/v1/ops/tasks');

        $response
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment([
                'task_key' => 'one_click_radar_refresh',
                'name' => '一键刷新热点雷达',
            ]);
    }

    public function test_it_runs_one_click_radar_refresh_and_records_steps(): void
    {
        Http::fake([
            '*' => Http::response(['code' => 0, 'message' => 'ok'], 200),
        ]);

        $response = $this->postJson('/api/v1/ops/tasks/one_click_radar_refresh/run', [
            'symbols' => ['300059', '000001'],
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-21',
            'limit' => 20,
            'triggered_by' => 'feature-test',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.task_key', 'one_click_radar_refresh')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.triggered_by', 'feature-test')
            ->assertJsonPath('data.result.steps.0.name', '刷新股票行情快照');

        $this->assertDatabaseHas('ops_task_runs', [
            'task_key' => 'one_click_radar_refresh',
            'status' => 'succeeded',
        ]);
        $this->assertGreaterThanOrEqual(8, count(OpsTaskRun::query()->firstOrFail()->result['steps']));
    }

    public function test_it_records_failed_task_runs(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'service unavailable'], 503),
        ]);

        $response = $this->postJson('/api/v1/ops/tasks/fetch_hot_news/run', [
            'limit' => 10,
        ]);

        $response
            ->assertStatus(500)
            ->assertJsonPath('code', 1)
            ->assertJsonPath('data.task_key', 'fetch_hot_news')
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseHas('ops_task_runs', [
            'task_key' => 'fetch_hot_news',
            'status' => 'failed',
        ]);
    }

    public function test_console_command_runs_ops_task_for_scheduler(): void
    {
        Http::fake([
            '*' => Http::response(['code' => 0, 'message' => 'ok'], 200),
        ]);

        $this->artisan('ops:run-task', [
            'taskKey' => 'one_click_radar_refresh',
            '--symbols' => '300059,000001',
            '--limit' => '10',
            '--triggered-by' => 'scheduler-test',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('ops_task_runs', [
            'task_key' => 'one_click_radar_refresh',
            'status' => 'succeeded',
            'triggered_by' => 'scheduler-test',
        ]);
    }

    public function test_one_click_radar_continues_when_one_step_fails(): void
    {
        $requestCount = 0;
        Http::fake(function () use (&$requestCount) {
            $requestCount++;

            return $requestCount === 1
                ? Http::response(['message' => 'quote provider failed'], 500)
                : Http::response(['code' => 0, 'message' => 'ok'], 200);
        });

        $response = $this->postJson('/api/v1/ops/tasks/one_click_radar_refresh/run', [
            'symbols' => '300059,000001',
            'limit' => 10,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'partial_success')
            ->assertJsonPath('data.result.steps.0.status', 'failed')
            ->assertJsonPath('data.result.steps.1.status', 'succeeded');

        $this->assertDatabaseHas('ops_task_runs', [
            'task_key' => 'one_click_radar_refresh',
            'status' => 'partial_success',
        ]);
    }
}

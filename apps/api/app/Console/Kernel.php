<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        if (config('services.ops.auto_radar_enabled', true)) {
            $interval = max(5, min(10, (int) config('services.ops.radar_interval_minutes', 10)));
            $symbols = (string) config('services.ops.radar_symbols', '300059,000001,002311,300687,601127');
            $limit = max(1, min(200, (int) config('services.ops.radar_limit', 50)));

            $schedule
                ->command("ops:run-task one_click_radar_refresh --symbols={$symbols} --limit={$limit} --triggered-by=scheduler")
                ->cron("*/{$interval} * * * *")
                ->withoutOverlapping(60);
        } else {
            $schedule->command('signals:rebuild')->everyTenMinutes()->withoutOverlapping();
            $schedule->command('signals:evaluate')->hourly()->withoutOverlapping();
        }

        $schedule->command('signals:dispatch --limit=100')->everyMinute()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

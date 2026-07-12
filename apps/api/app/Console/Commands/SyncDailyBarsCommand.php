<?php

namespace App\Console\Commands;

use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class SyncDailyBarsCommand extends Command
{
    protected $signature = 'market:sync-daily-bars
        {symbol : A-share stock symbol, such as 000001}
        {--start= : Start date, format YYYY-MM-DD}
        {--end= : End date, format YYYY-MM-DD}
        {--adjust=none : none, qfq or hfq}
        {--provider=akshare}';

    protected $description = 'Sync daily K-line data for a stock symbol.';

    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->intelligenceClient->syncDailyBars(
            (string) $this->argument('symbol'),
            $this->option('start') ? (string) $this->option('start') : null,
            $this->option('end') ? (string) $this->option('end') : null,
            (string) $this->option('adjust'),
            (string) $this->option('provider'),
        );

        $this->info('Daily bar sync request accepted.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class SyncIndexDailyBarsCommand extends Command
{
    protected $signature = 'market:sync-index-daily-bars
        {code : Index code such as sh000001}
        {--start= : Start date, format YYYY-MM-DD}
        {--end= : End date, format YYYY-MM-DD}
        {--provider=akshare}';

    protected $description = 'Sync daily bars for a market index.';

    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->intelligenceClient->syncIndexDailyBars(
            (string) $this->argument('code'),
            $this->option('start') ? (string) $this->option('start') : null,
            $this->option('end') ? (string) $this->option('end') : null,
            (string) $this->option('provider'),
        );

        $this->info('Index daily bar sync request accepted.');

        return self::SUCCESS;
    }
}

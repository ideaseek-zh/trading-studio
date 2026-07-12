<?php

namespace App\Console\Commands;

use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class SyncQuotesCommand extends Command
{
    protected $signature = 'market:sync-quotes {symbol* : One or more stock symbols, such as 000001 600000} {--provider=akshare}';

    protected $description = 'Sync real-time quote snapshots for one or more A-share securities.';

    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->intelligenceClient->syncQuotes($this->argument('symbol'), (string) $this->option('provider'));
        $this->info('Quote sync request accepted.');

        return self::SUCCESS;
    }
}

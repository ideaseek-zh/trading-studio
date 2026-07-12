<?php

namespace App\Console\Commands;

use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class SyncNewsSourcesCommand extends Command
{
    protected $signature = 'news:sync-sources {--provider=akshare}';

    protected $description = 'Seed or refresh configured news sources from the intelligence service.';

    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->intelligenceClient->syncNewsSources((string) $this->option('provider'));
        $this->info('News source sync request accepted.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class SyncIndicesCommand extends Command
{
    protected $signature = 'market:sync-indices {--code=* : Index code such as sh000001} {--provider=akshare}';

    protected $description = 'Sync major market index snapshots.';

    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->intelligenceClient->syncIndices(
            $this->option('code'),
            (string) $this->option('provider')
        );

        $this->info('Index snapshot sync request accepted.');

        return self::SUCCESS;
    }
}

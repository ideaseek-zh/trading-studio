<?php

namespace App\Console\Commands;

use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class SyncNewsCommand extends Command
{
    protected $signature = 'news:sync
        {--provider=akshare}
        {--scope=* : global|stock|notice|disclosure}
        {--symbols= : Comma separated stock symbols}
        {--start= : Start date in YYYY-MM-DD}
        {--end= : End date in YYYY-MM-DD}
        {--limit=30 : Max rows fetched per source scope}';

    protected $description = 'Trigger the intelligence service to fetch, deduplicate, validate, and associate news.';

    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $scopes = $this->option('scope');
        if ($scopes === []) {
            $scopes = ['global', 'notice'];
        }

        $symbols = collect(explode(',', (string) $this->option('symbols')))
            ->map(fn (string $symbol): string => trim($symbol))
            ->filter()
            ->values()
            ->all();

        $this->intelligenceClient->syncNews(
            $scopes,
            $symbols,
            $this->option('start') ?: null,
            $this->option('end') ?: null,
            (int) $this->option('limit'),
            (string) $this->option('provider'),
        );

        $this->info('News sync request accepted.');

        return self::SUCCESS;
    }
}

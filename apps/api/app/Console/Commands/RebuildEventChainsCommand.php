<?php

namespace App\Console\Commands;

use App\Models\Security;
use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class RebuildEventChainsCommand extends Command
{
    protected $signature = 'news:rebuild-event-chains
        {--symbol= : Optional stock symbol. If omitted, rebuild all event chains.}';

    protected $description = 'Rebuild standardized event chains and timelines from existing events.';

    public function __construct(
        private readonly IntelligenceClient $intelligenceClient
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $securityId = null;
        $symbol = trim((string) $this->option('symbol'));

        if ($symbol !== '') {
            $security = Security::query()->where('symbol', $symbol)->first();
            if ($security === null) {
                $this->error("Security not found for symbol {$symbol}.");

                return self::FAILURE;
            }

            $securityId = $security->id;
        }

        $this->intelligenceClient->rebuildEventChains($securityId);
        $this->info('Event chain rebuild request accepted.');

        return self::SUCCESS;
    }
}

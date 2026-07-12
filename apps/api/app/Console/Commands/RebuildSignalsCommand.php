<?php

namespace App\Console\Commands;

use App\Models\Security;
use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class RebuildSignalsCommand extends Command
{
    protected $signature = 'signals:rebuild
        {--symbol= : Optional stock symbol}
        {--event-chain-id= : Optional event chain id}';

    protected $description = 'Rebuild trading signals from standardized event chains.';

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

        $eventChainId = $this->option('event-chain-id') ? (int) $this->option('event-chain-id') : null;

        $this->intelligenceClient->rebuildSignals($securityId, $eventChainId);
        $this->info('Signal rebuild request accepted.');

        return self::SUCCESS;
    }
}

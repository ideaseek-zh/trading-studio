<?php

namespace App\Console\Commands;

use App\Models\Security;
use App\Services\IntelligenceClient;
use Illuminate\Console\Command;

class EvaluateSignalsCommand extends Command
{
    protected $signature = 'signals:evaluate
        {--signal-id= : Optional signal id}
        {--symbol= : Optional stock symbol}';

    protected $description = 'Refresh signal explanation panels and baseline post-performance evaluations.';

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

        $signalId = $this->option('signal-id') ? (int) $this->option('signal-id') : null;
        $this->intelligenceClient->rebuildSignalInsights($signalId, $securityId);
        $this->info('Signal insight rebuild request accepted.');

        return self::SUCCESS;
    }
}

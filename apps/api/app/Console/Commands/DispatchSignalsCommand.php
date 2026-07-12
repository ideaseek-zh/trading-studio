<?php

namespace App\Console\Commands;

use App\Services\SignalDeliveryService;
use Illuminate\Console\Command;

class DispatchSignalsCommand extends Command
{
    protected $signature = 'signals:dispatch
        {--limit=50 : Max webhook deliveries per run}
        {--enqueue-only : Only enqueue deliveries, do not dispatch webhooks}';

    protected $description = 'Enqueue and dispatch trading signal webhook deliveries.';

    public function __construct(
        private readonly SignalDeliveryService $deliveryService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $enqueued = $this->deliveryService->enqueuePendingDeliveries();
        $this->info("Enqueued {$enqueued} signal deliveries.");

        if ($this->option('enqueue-only')) {
            return self::SUCCESS;
        }

        $result = $this->deliveryService->dispatchPendingWebhooks((int) $this->option('limit'));
        $this->info("Queued {$result['queued']} deliveries, sent {$result['sent']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}

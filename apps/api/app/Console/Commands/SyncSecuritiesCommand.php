<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncSecuritiesCommand extends Command
{
    protected $signature = 'market:sync-securities {--provider=akshare}';

    protected $description = 'Trigger the FastAPI intelligence service to sync A-share security master data.';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('services.intelligence.base_url'), '/');
        $serviceToken = (string) config('services.intelligence.service_token');

        $response = Http::timeout((int) config('services.intelligence.timeout', 30))
            ->acceptJson()
            ->withHeaders([
                'X-Service-Token' => $serviceToken,
            ])
            ->post("{$baseUrl}/internal/v1/data/sync/securities", [
                'provider' => $this->option('provider'),
            ]);

        if ($response->failed()) {
            $this->error('Security sync request failed.');
            $this->line($response->body());

            return self::FAILURE;
        }

        $this->info('Security sync request accepted.');
        $this->line($response->body());

        return self::SUCCESS;
    }
}

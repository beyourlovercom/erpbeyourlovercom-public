<?php

namespace App\Modules\Kol\Experience\Console\Commands;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\ConnectorException;
use Illuminate\Console\Command;

final class AffiliateWpHealthCommand extends Command
{
    protected $signature = 'kol:affiliatewp-health {--json : Emit redacted health result as JSON}';

    protected $description = 'Probe Andy Core AffiliateWP connector health (no secrets disclosed)';

    public function handle(AffiliateWpGateway $gateway): int
    {
        $descriptor = $gateway->descriptor();

        if (! $descriptor['configured']) {
            $this->error('AffiliateWP connector is not configured. Check ANDY_CORE_* env vars.');
            return self::FAILURE;
        }

        try {
            $response = $gateway->health();
            $data = is_array($response->data) ? $response->data : [];

            $result = [
                'ok' => $response->ok,
                'contract_version' => $response->contractVersion,
                'request_id' => $response->requestId,
                'connection_key' => $response->connectionKey,
                'url' => $descriptor['url'],
                'key_id' => $descriptor['key_id'],
                'andy_core_version' => $data['andy_core_version'] ?? null,
                'wp_version' => $data['wp_version'] ?? null,
                'woocommerce_active' => $data['woocommerce_active'] ?? null,
                'affiliatewp_active' => $data['affiliatewp_active'] ?? null,
                'server_time' => $data['server_time'] ?? null,
                'writable' => $data['writable'] ?? null,
            ];

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            } else {
                $this->info('AffiliateWP connector: '.($response->ok ? 'OK' : 'ERROR'));
                $this->line('URL: '.$descriptor['url']);
                $this->line('Connection key: '.$response->connectionKey);
                $this->line('Contract version: '.$response->contractVersion);
                foreach (['andy_core_version', 'wp_version', 'woocommerce_active', 'affiliatewp_active', 'writable'] as $key) {
                    if (array_key_exists($key, $result) && $result[$key] !== null) {
                        $this->line($key.': '.json_encode($result[$key]));
                    }
                }
            }

            return $response->ok ? self::SUCCESS : self::FAILURE;
        } catch (ConnectorException $e) {
            $this->error('Connector error ['.$e->errorCode.']: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}

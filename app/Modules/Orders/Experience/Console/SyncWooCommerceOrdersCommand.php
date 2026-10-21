<?php

namespace App\Modules\Orders\Experience\Console;

use App\Modules\Orders\Application\Inbound\WooCommerceInboundService;
use Illuminate\Console\Command;

final class SyncWooCommerceOrdersCommand extends Command
{
    protected $signature = 'orders:sync-woocommerce {--json : Emit the bounded automatic sync result as JSON}';

    protected $description = 'Automatically synchronize WooCommerce Orders into canonical ERP Orders';

    public function handle(WooCommerceInboundService $service): int
    {
        try {
            $result = $service->importAutomaticIncremental();
            $safe = [
                'run_id' => $result['run_id'],
                'run_status' => $result['run_status'],
                'seen' => $result['seen'],
                'created' => $result['created'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'pages' => $result['pages'],
                'checkpoint_before' => $result['checkpoint_before'],
                'checkpoint_after' => $result['checkpoint_after'],
            ];

            if ($this->option('json')) {
                $this->line((string) json_encode($safe, JSON_UNESCAPED_SLASHES));
            } else {
                $this->info(sprintf(
                    'Woo Order automatic sync: seen=%d created=%d updated=%d skipped=%d failed=%d status=%s',
                    $safe['seen'],
                    $safe['created'],
                    $safe['updated'],
                    $safe['skipped'],
                    $safe['failed'],
                    $safe['run_status'],
                ));
            }

            return $safe['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}

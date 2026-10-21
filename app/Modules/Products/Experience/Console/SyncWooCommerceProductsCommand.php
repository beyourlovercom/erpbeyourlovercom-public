<?php

namespace App\Modules\Products\Experience\Console;

use App\Modules\Products\Application\WooCommerceProductInboundService;
use Illuminate\Console\Command;

final class SyncWooCommerceProductsCommand extends Command
{
    protected $signature = 'products:sync-woocommerce {--full : Perform a bounded full snapshot} {--incremental : Use the provider modified-time cursor} {--product= : Sync one remote WooCommerce Product ID}';
    protected $description = 'Synchronize WooCommerce Products and media into Product-owned inbound evidence';

    public function handle(WooCommerceProductInboundService $service): int
    {
        $single = $this->option('product');
        if ($single !== null && (! ctype_digit((string) $single) || (int) $single <= 0)) { $this->error('The --product value must be a positive integer.'); return self::FAILURE; }
        try {
            $result = $service->sync(null, (bool) $this->option('full'), $single === null ? null : (int) $single);
            $status = $result['run']->status instanceof \BackedEnum ? $result['run']->status->value : (string) $result['run']->status;
            $this->info('WooCommerce Product sync finished: '.$result['processed'].' item(s), status='.$status.'.');
            return $status === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
    }
}

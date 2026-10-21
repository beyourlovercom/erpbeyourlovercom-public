<?php

namespace App\Modules\Kol\Experience\Console\Commands;

use App\Modules\Kol\Application\AffiliateWp\AffiliateAccountPreviewService;
use Illuminate\Console\Command;
use Throwable;

final class AffiliateAccountPreviewCommand extends Command
{
    protected $signature = 'kol:affiliate-account-preview {--json : Emit redacted aggregate JSON only}';

    protected $description = 'Read-only AffiliateWP account preview against current ERP mirror and KOL bindings.';

    public function handle(AffiliateAccountPreviewService $service): int
    {
        try {
            $result = $service->preview();
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['ok' => false, 'error' => 'AFFILIATE_ACCOUNT_PREVIEW_FAILED'], JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Affiliate account preview failed closed.');
            }
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('Affiliate account preview PASS (read-only).');
        foreach ($result as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $this->line($key.': '.json_encode($value, JSON_UNESCAPED_SLASHES));
            }
        }
        return self::SUCCESS;
    }
}

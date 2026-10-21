<?php

namespace App\Modules\Kol\Experience\Console\Commands;

use App\Modules\Kol\Application\AffiliateWp\ReferralOrderResolutionService;
use Illuminate\Console\Command;

final class ResolveReferralOrdersCommand extends Command
{
    protected $signature = 'kol:reresolve-referral-orders
        {--connection= : Limit to one AffiliateWP connection key}
        {--apply : Persist exact local Referral to canonical Woo Order resolution}
        {--expect-seen= : Required Production preflight count}
        {--expect-ready= : Required Production exact-ready count}
        {--expect-unresolved= : Required Production unresolved count}
        {--expect-ambiguous= : Required Production ambiguous count}
        {--expect-change= : Required Production changed-row count}';

    protected $description = 'Preview or gated-apply P8 local Referral to canonical Woo Order re-resolution.';

    public function handle(ReferralOrderResolutionService $service): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && trim($connection) !== '' ? trim($connection) : null;
        $preflight = $service->preview($connection);
        if (! $this->option('apply')) {
            $this->line((string) json_encode($preflight, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        if (! $this->applyAllowed()) {
            $this->error('Production --apply requires ERP_KOL_REFERRAL_ORDER_RESOLUTION_PRODUCTION_APPLY_ENABLED=true for this process.');
            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->expectedCountsMatch($preflight)) {
            $this->line((string) json_encode(['mode'=>'apply','gate'=>'blocked_preflight_mismatch','preflight'=>$preflight], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }
        $summary = $service->apply($connection);
        $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }

    private function applyAllowed(): bool
    {
        if (app()->environment(['local','testing'])) return true;
        if (! app()->environment('production')) return false;
        return filter_var(getenv('ERP_KOL_REFERRAL_ORDER_RESOLUTION_PRODUCTION_APPLY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    }

    /** @param array<string,mixed> $summary */
    private function expectedCountsMatch(array $summary): bool
    {
        foreach (['seen','ready','unresolved','ambiguous','change'] as $name) {
            $expected = $this->expectedInt('expect-'.$name);
            $actualKey = $name === 'change' ? 'changed' : $name;
            if ($expected === null || (int) $summary[$actualKey] !== $expected) return false;
        }
        return true;
    }

    private function expectedInt(string $option): ?int
    {
        $value = $this->option($option);
        return $value !== null && preg_match('/^\d+$/D', (string) $value) === 1 ? (int) $value : null;
    }
}

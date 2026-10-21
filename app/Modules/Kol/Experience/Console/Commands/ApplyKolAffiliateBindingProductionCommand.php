<?php

namespace App\Modules\Kol\Experience\Console\Commands;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Application\AffiliateWp\KolAffiliateBindingActivationService;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateBindingActivationBatch;
use Illuminate\Console\Command;
use Throwable;

final class ApplyKolAffiliateBindingProductionCommand extends Command
{
    protected $signature = 'kol:affiliate-binding-apply-production
        {batch_id : P4B binding activation batch id}
        {--plan-checksum= : Exact 64-character P4B plan checksum}
        {--ready-count= : Exact ready binding count}
        {--actor-id= : Active ERP actor id with KOL binding authority}
        {--confirm= : Must equal APPLY_EXACT_KOL_AFFILIATE_BINDINGS}
        {--json : Emit a compact machine-readable result}';

    protected $description = 'Apply an exact prepared KOL Affiliate binding batch in Production only.';

    public function handle(KolAffiliateBindingActivationService $service): int
    {
        if (! app()->environment('production')) {
            $this->error('This command is available only in Production.');

            return self::FAILURE;
        }
        if ((string) $this->option('confirm') !== KolAffiliateBindingActivationService::PRODUCTION_CONFIRMATION) {
            $this->error('Exact Production confirmation phrase is required.');

            return self::FAILURE;
        }

        $batchId = $this->positiveInt($this->argument('batch_id'));
        $readyCount = $this->nonNegativeInt($this->option('ready-count'));
        $actorId = $this->positiveInt($this->option('actor-id'));
        $checksum = strtolower(trim((string) $this->option('plan-checksum')));
        if ($batchId === null || $readyCount === null || $actorId === null || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            $this->error('Batch id, plan checksum, ready count and actor id must be exact and valid.');

            return self::FAILURE;
        }

        $batch = KolAffiliateBindingActivationBatch::query()->find($batchId);
        if (! $batch) {
            $this->error('P4B binding batch was not found.');

            return self::FAILURE;
        }
        $actor = User::query()->whereKey($actorId)->where('is_active', true)->first();
        if (! $actor) {
            $this->error('Active ERP actor was not found.');

            return self::FAILURE;
        }

        try {
            $applied = $service->applyProductionAuthorized(
                $batch,
                $checksum,
                $readyCount,
                $actor,
                (string) $this->option('confirm'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $result = [
            'ok' => true,
            'batch_id' => (int) $applied->id,
            'status' => $applied->status,
            'ready_count' => (int) $applied->ready_count,
            'applied_at' => $applied->applied_at?->toIso8601String(),
        ];
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('KOL Affiliate binding Production apply completed.');
            $this->line('Batch: '.$result['batch_id']);
            $this->line('Ready count: '.$result['ready_count']);
        }

        return self::SUCCESS;
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) ? (int) $value : null;
    }
}

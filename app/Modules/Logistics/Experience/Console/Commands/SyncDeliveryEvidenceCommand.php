<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Logistics\Application\DeliveryEvidenceIngestService;
use Illuminate\Console\Command;
use Throwable;

final class SyncDeliveryEvidenceCommand extends Command
{
    protected $signature = 'logistics:sync-delivery-evidence
        {--shipment-id= : Exact canonical shipment ID}
        {--apply : Persist the reviewed delivery evidence}
        {--expect-fingerprint= : Required exact dry-run fingerprint when applying}
        {--json : Emit safe JSON evidence}';

    protected $description = 'Preview or ingest canonical carrier delivery evidence without changing shipment status';

    public function handle(DeliveryEvidenceIngestService $service): int
    {
        $shipmentId = (int) $this->option('shipment-id');
        if ($shipmentId <= 0) {
            return $this->emit(['decision' => 'blocked', 'reason' => 'shipment_id_required'], self::FAILURE);
        }

        try {
            $preview = $service->preview($shipmentId);
        } catch (Throwable $exception) {
            return $this->emit(['decision' => 'blocked', 'reason' => 'preview_failed', 'error' => $exception->getMessage()], self::FAILURE);
        }

        $preview['mode'] = $this->option('apply') ? 'apply' : 'dry_run';
        if (! $this->option('apply')) {
            return $this->emit($preview, ($preview['decision'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS);
        }
        if (($preview['decision'] ?? null) !== 'change') {
            $preview['gate'] = 'preview_not_change';

            return $this->emit($preview, self::FAILURE);
        }

        $expected = trim((string) $this->option('expect-fingerprint'));
        if ($expected === '' || ! hash_equals((string) $preview['fingerprint'], $expected)) {
            $preview['gate'] = 'fingerprint_mismatch';

            return $this->emit($preview, self::FAILURE);
        }

        try {
            $result = $service->apply($shipmentId, $expected);
        } catch (Throwable $exception) {
            $preview['gate'] = 'apply_failed';
            $preview['error'] = $exception->getMessage();

            return $this->emit($preview, self::FAILURE);
        }

        return $this->emit(['mode' => 'apply', 'decision' => 'change', ...$result], self::SUCCESS);
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $exit): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }
}

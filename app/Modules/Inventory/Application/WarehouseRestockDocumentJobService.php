<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockDocumentJob;
use App\Modules\Inventory\Infrastructure\Models\WarehouseRestockPlan;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseRestockDocumentJobService
{
    public const DOCUMENT_BLUE_LABELS = 'blue_labels';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    private const SCHEMA_VERSION = 'byl.blue-label.job.v1';
    private const TEMPLATE_VERSION = 'blue-label-picture-master-v1';
    private const GENERATOR_VERSION = 'blue-label-runtime-v1';
    private const DISPATCH_MODE = 'codex_dc_windows_bartender_v1';
    private const MAX_PI_ITEMS = 18;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly WarehouseRestockPackageService $packages,
    ) {}

    /** @return array{job:WarehouseRestockDocumentJob,created:bool} */
    public function requestBlueLabels(
        Warehouse $warehouse,
        int $planId,
        ?Authenticatable $actor,
    ): array {
        return DB::transaction(function () use ($warehouse, $planId, $actor): array {
            $plan = WarehouseRestockPlan::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereKey($planId)
                ->lockForUpdate()
                ->first();

            if ($plan === null || $plan->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'blue_label_job' => '备货计划不存在、仓库不匹配或尚未确认。',
                ]);
            }

            $existing = WarehouseRestockDocumentJob::query()
                ->where('restock_plan_id', $plan->id)
                ->where('document_kind', self::DOCUMENT_BLUE_LABELS)
                ->first();

            if ($existing !== null) {
                return ['job' => $existing, 'created' => false];
            }

            $items = $plan->items()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'blue_label_job' => '备货计划没有可生成蓝标的冻结明细。',
                ]);
            }
            if ($items->count() > self::MAX_PI_ITEMS) {
                throw ValidationException::withMessages([
                    'blue_label_job' => 'PI V1 最多支持 '.self::MAX_PI_ITEMS.' 个 SKU，请拆分备货计划后再生成。',
                ]);
            }

            $payloadItems = [];
            $seenLabelSkus = [];

            foreach ($items as $item) {
                $productSku = trim((string) $item->product_sku);
                $labelSku = trim((string) $item->label_sku);
                $description = trim((string) $item->label_description);
                $productName = trim((string) $item->product_name);
                $quantity = (int) $item->final_restock_qty;

                if ($productSku === '' || $labelSku === '' || $description === '' || $productName === '' || $quantity <= 0) {
                    throw ValidationException::withMessages([
                        'blue_label_job' => '备货计划存在不完整的蓝标冻结字段，不能进入执行队列。',
                    ]);
                }

                if (! preg_match('/^[A-Za-z0-9._-]+$/', $labelSku)) {
                    throw ValidationException::withMessages([
                        'blue_label_job' => '蓝标 SKU 不符合生成规则：'.$labelSku,
                    ]);
                }

                if (isset($seenLabelSkus[$labelSku])) {
                    throw ValidationException::withMessages([
                        'blue_label_job' => '备货计划存在重复蓝标 SKU：'.$labelSku,
                    ]);
                }
                $seenLabelSkus[$labelSku] = true;

                $payloadItems[] = [
                    'product_sku' => $productSku,
                    'label_sku' => $labelSku,
                    'label_description' => $description,
                    'product_name' => $productName,
                    'restock_qty' => $quantity,
                    'units_per_carton' => $item->units_per_carton !== null ? (int) $item->units_per_carton : null,
                    'carton_count' => $item->carton_count !== null ? (int) $item->carton_count : null,
                    'packaging_length_cm' => $item->packaging_length_cm,
                    'packaging_width_cm' => $item->packaging_width_cm,
                    'packaging_height_cm' => $item->packaging_height_cm,
                    'packaged_weight_kg' => $item->packaged_weight_kg,
                    'quantity_available' => (int) $item->quantity_available,
                ];
            }

            $warehouseCode = strtoupper((string) preg_replace(
                '/[^A-Z0-9]+/i',
                '',
                (string) $warehouse->code,
            ));
            if ($warehouseCode === '') $warehouseCode = 'WAREHOUSE';

            $reference = sprintf(
                'BLJ-%s-%s-%s',
                $warehouseCode,
                now()->format('Ymd'),
                substr((string) Str::ulid(), -10),
            );

            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'job_id' => $reference,
                'plan_reference' => (string) $plan->reference,
                'plan_snapshot_checksum' => (string) $plan->snapshot_checksum,
                'template_version' => self::TEMPLATE_VERSION,
                'pi_template_version' => '007-pi-sea-canonical-v1',
                'warehouse_code' => (string) $warehouse->code,
                'warehouse_name' => (string) $warehouse->name,
                'confirmed_date' => $plan->confirmed_at?->format('Y/m/d'),
                'items' => $payloadItems,
            ];
            $payloadChecksum = $this->checksum($payload);

            $job = WarehouseRestockDocumentJob::query()->create([
                'warehouse_id' => $warehouse->id,
                'restock_plan_id' => $plan->id,
                'reference' => $reference,
                'document_kind' => self::DOCUMENT_BLUE_LABELS,
                'status' => self::STATUS_QUEUED,
                'schema_version' => self::SCHEMA_VERSION,
                'template_version' => self::TEMPLATE_VERSION,
                'generator_version' => self::GENERATOR_VERSION,
                'dispatch_mode' => self::DISPATCH_MODE,
                'plan_snapshot_checksum' => (string) $plan->snapshot_checksum,
                'payload_checksum' => $payloadChecksum,
                'payload' => $payload,
                'attempt_count' => 0,
                'requested_by' => $actor?->getAuthIdentifier(),
                'requested_at' => now(),
            ]);

            $this->audit->record(
                $actor
                    ? AuditActor::user($actor, AuditSource::Web)
                    : AuditActor::system(AuditSource::InternalSystem),
                'inventory.restock_document_job.created',
                $job::class,
                $job->id,
                after: [
                    'reference' => $job->reference,
                    'warehouse_id' => (int) $warehouse->id,
                    'restock_plan_id' => (int) $plan->id,
                    'plan_reference' => (string) $plan->reference,
                    'document_kind' => self::DOCUMENT_BLUE_LABELS,
                    'status' => self::STATUS_QUEUED,
                    'payload_checksum' => $payloadChecksum,
                    'items' => count($payloadItems),
                ],
                reason: 'inventory_restock_blue_label_job_created',
            );

            return ['job' => $job, 'created' => true];
        });
    }

    public function claimNext(string $workerId): ?WarehouseRestockDocumentJob
    {
        return DB::transaction(function () use ($workerId): ?WarehouseRestockDocumentJob {
            $this->recoverExpiredJobs();

            $job = WarehouseRestockDocumentJob::query()
                ->where('document_kind', self::DOCUMENT_BLUE_LABELS)
                ->where('status', self::STATUS_QUEUED)
                ->where(function ($query): void {
                    $query->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('next_attempt_at')
                ->orderBy('requested_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($job === null) return null;

            $now = now();
            $job->forceFill([
                'status' => self::STATUS_CLAIMED,
                'worker_id' => $workerId,
                'attempt_count' => (int) $job->attempt_count + 1,
                'next_attempt_at' => null,
                'claimed_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                'last_heartbeat_at' => $now,
            ])->save();

            $this->recordWorkerEvent($job, 'inventory.restock_document_job.claimed', [
                'worker_id' => $workerId,
                'attempt_count' => (int) $job->attempt_count,
                'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            ]);

            return $job->fresh();
        });
    }

    public function start(string $reference, string $workerId): WarehouseRestockDocumentJob
    {
        return $this->mutateOwnedActiveJob(
            $reference,
            $workerId,
            [self::STATUS_CLAIMED],
            function (WarehouseRestockDocumentJob $job): void {
                $now = now();
                $job->forceFill([
                    'status' => self::STATUS_RUNNING,
                    'started_at' => $job->started_at ?? $now,
                    'last_heartbeat_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                ])->save();

                $this->recordWorkerEvent($job, 'inventory.restock_document_job.started', [
                    'worker_id' => $job->worker_id,
                ]);
            },
        );
    }

    public function heartbeat(string $reference, string $workerId): WarehouseRestockDocumentJob
    {
        return $this->mutateOwnedActiveJob(
            $reference,
            $workerId,
            [self::STATUS_CLAIMED, self::STATUS_RUNNING],
            function (WarehouseRestockDocumentJob $job): void {
                $now = now();
                $job->forceFill([
                    'last_heartbeat_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                ])->save();
            },
        );
    }

    public function storeArtifact(
        string $reference,
        string $workerId,
        string $kind,
        string $labelSku,
        UploadedFile $file,
        string $sha256,
    ): array {
        $result = [];

        $this->mutateOwnedActiveJob(
            $reference,
            $workerId,
            [self::STATUS_RUNNING],
            function (WarehouseRestockDocumentJob $job) use ($kind, $labelSku, $file, $sha256, &$result): void {
                $result = $this->packages->storeWorkerArtifact(
                    $job,
                    $kind,
                    $labelSku,
                    $file,
                    strtolower(trim($sha256)),
                );
            },
        );

        return $result;
    }

    public function complete(
        string $reference,
        string $workerId,
        array $manifest,
        string $outputRoot,
    ): WarehouseRestockDocumentJob {
        return $this->mutateOwnedActiveJob(
            $reference,
            $workerId,
            [self::STATUS_RUNNING],
            function (WarehouseRestockDocumentJob $job) use ($manifest, $outputRoot): void {
                $this->validateResultManifest($job, $manifest);
                $package = $this->packages->finalize($job, $manifest, $outputRoot);
                $finalManifest = $package['manifest'];

                $now = now();
                $job->forceFill([
                    'status' => self::STATUS_SUCCEEDED,
                    'completed_at' => $now,
                    'failed_at' => null,
                    'last_heartbeat_at' => $now,
                    'lease_expires_at' => null,
                    'result_manifest' => $finalManifest,
                    'result_manifest_checksum' => $this->checksum($finalManifest),
                    'output_root' => $package['root'],
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                $this->recordWorkerEvent($job, 'inventory.restock_document_job.succeeded', [
                    'worker_id' => $job->worker_id,
                    'output_root' => $package['root'],
                    'artifacts' => count((array) ($manifest['artifacts'] ?? [])),
                    'pi_path' => $package['pi_path'],
                    'package_path' => $package['package_path'],
                    'result_manifest_checksum' => $job->result_manifest_checksum,
                ]);
            },
        );
    }

    public function fail(
        string $reference,
        string $workerId,
        string $errorCode,
        string $errorMessage,
    ): WarehouseRestockDocumentJob {
        return $this->mutateOwnedActiveJob(
            $reference,
            $workerId,
            [self::STATUS_CLAIMED, self::STATUS_RUNNING],
            function (WarehouseRestockDocumentJob $job) use ($errorCode, $errorMessage): void {
                $now = now();
                $retryable = $this->isRetryableError($errorCode)
                    && (int) $job->attempt_count < $this->maxAttempts();

                if ($retryable) {
                    $this->packages->discardIncomplete($job);
                    $retryAt = $now->copy()->addSeconds($this->retryDelaySeconds((int) $job->attempt_count));
                    $previousWorker = $job->worker_id;

                    $job->forceFill([
                        'status' => self::STATUS_QUEUED,
                        'worker_id' => null,
                        'next_attempt_at' => $retryAt,
                        'claimed_at' => null,
                        'lease_expires_at' => null,
                        'started_at' => null,
                        'last_heartbeat_at' => $now,
                        'failed_at' => null,
                        'last_error_code' => $errorCode,
                        'last_error_message' => $errorMessage,
                    ])->save();

                    $this->recordWorkerEvent($job, 'inventory.restock_document_job.retry_scheduled', [
                        'worker_id' => $previousWorker,
                        'attempt_count' => (int) $job->attempt_count,
                        'next_attempt_at' => $retryAt->toIso8601String(),
                        'error_code' => $errorCode,
                    ]);

                    return;
                }

                $job->forceFill([
                    'status' => self::STATUS_FAILED,
                    'failed_at' => $now,
                    'last_heartbeat_at' => $now,
                    'lease_expires_at' => null,
                    'next_attempt_at' => null,
                    'last_error_code' => $errorCode,
                    'last_error_message' => $errorMessage,
                ])->save();

                $this->recordWorkerEvent($job, 'inventory.restock_document_job.failed', [
                    'worker_id' => $job->worker_id,
                    'attempt_count' => (int) $job->attempt_count,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                ]);
            },
        );
    }

    public function workerPayload(WarehouseRestockDocumentJob $job): array
    {
        return [
            'reference' => $job->reference,
            'status' => $job->status,
            'attempt_count' => (int) $job->attempt_count,
            'worker_id' => $job->worker_id,
            'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
            'schema_version' => $job->schema_version,
            'template_version' => $job->template_version,
            'generator_version' => $job->generator_version,
            'dispatch_mode' => $job->dispatch_mode,
            'payload_checksum' => $job->payload_checksum,
            'payload' => $job->payload,
            'output_root' => $job->output_root,
            'last_error_code' => $job->last_error_code,
            'last_error_message' => $job->last_error_message,
        ];
    }

    private function mutateOwnedActiveJob(
        string $reference,
        string $workerId,
        array $allowedStatuses,
        callable $mutation,
    ): WarehouseRestockDocumentJob {
        return DB::transaction(function () use ($reference, $workerId, $allowedStatuses, $mutation): WarehouseRestockDocumentJob {
            $job = WarehouseRestockDocumentJob::query()
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标任务不存在。',
                ]);
            }

            if (! in_array($job->status, $allowedStatuses, true)) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标任务当前状态不允许此 Worker 操作：'.$job->status,
                ]);
            }

            if (! is_string($job->worker_id) || ! hash_equals($job->worker_id, $workerId)) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标任务不属于当前 Worker。',
                ]);
            }

            if ($job->lease_expires_at === null || $job->lease_expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标任务 Worker lease 已过期。',
                ]);
            }

            $mutation($job);

            return $job->fresh();
        });
    }

    private function validateResultManifest(
        WarehouseRestockDocumentJob $job,
        array $manifest,
    ): void {
        $plan = $job->plan()->firstOrFail();

        $required = [
            'schema_version' => 'byl.blue-label.output.v1',
            'generator_version' => self::GENERATOR_VERSION,
            'job_id' => $job->reference,
            'plan_reference' => (string) $plan->reference,
            'plan_snapshot_checksum' => (string) $job->plan_snapshot_checksum,
            'template_version' => self::TEMPLATE_VERSION,
        ];

        foreach ($required as $key => $expected) {
            $actual = (string) ($manifest[$key] ?? '');
            if ($actual === '' || ! hash_equals((string) $expected, $actual)) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标结果 manifest 字段不匹配：'.$key,
                ]);
            }
        }

        $artifacts = $manifest['artifacts'] ?? null;
        if (! is_array($artifacts)) {
            throw ValidationException::withMessages([
                'worker_job' => '蓝标结果 manifest 缺少 artifacts。',
            ]);
        }

        $expectedItems = collect((array) ($job->payload['items'] ?? []))
            ->keyBy(fn (array $item): string => (string) $item['label_sku']);

        if (count($artifacts) !== $expectedItems->count()) {
            throw ValidationException::withMessages([
                'worker_job' => '蓝标结果文件数量与冻结任务不一致。',
            ]);
        }

        $seen = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                throw ValidationException::withMessages(['worker_job' => '蓝标 artifact 格式无效。']);
            }

            $labelSku = trim((string) ($artifact['label_sku'] ?? ''));
            if ($labelSku === '' || isset($seen[$labelSku]) || ! $expectedItems->has($labelSku)) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标结果包含未知或重复 Label SKU：'.$labelSku,
                ]);
            }
            $seen[$labelSku] = true;

            $expected = $expectedItems->get($labelSku);
            if (
                ! hash_equals((string) $expected['product_sku'], (string) ($artifact['product_sku'] ?? ''))
                || (int) $expected['restock_qty'] !== (int) ($artifact['restock_qty'] ?? 0)
                || (string) ($artifact['barcode'] ?? '') !== 'Code128B'
            ) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标结果内容与冻结任务不一致：'.$labelSku,
                ]);
            }

            $size = $artifact['label_size_mm'] ?? null;
            if (! is_array($size) || count($size) < 2 || abs((float) $size[0] - 50.0) > 0.0001 || abs((float) $size[1] - 30.0) > 0.0001) {
                throw ValidationException::withMessages([
                    'worker_job' => '蓝标 PDF 尺寸不是精确 50×30 mm：'.$labelSku,
                ]);
            }

            foreach (['btw_sha256', 'pdf_sha256'] as $hashKey) {
                if (preg_match('/^[a-f0-9]{64}$/D', (string) ($artifact[$hashKey] ?? '')) !== 1) {
                    throw ValidationException::withMessages([
                        'worker_job' => '蓝标结果缺少有效文件 SHA-256：'.$labelSku,
                    ]);
                }
            }
        }
    }

    private function recoverExpiredJobs(): void
    {
        $expired = WarehouseRestockDocumentJob::query()
            ->where('document_kind', self::DOCUMENT_BLUE_LABELS)
            ->whereIn('status', [self::STATUS_CLAIMED, self::STATUS_RUNNING])
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($expired as $job) {
            $now = now();
            $previousWorker = $job->worker_id;

            if ((int) $job->attempt_count < $this->maxAttempts()) {
                $this->packages->discardIncomplete($job);
                $retryAt = $now->copy()->addSeconds($this->retryDelaySeconds((int) $job->attempt_count));

                $job->forceFill([
                    'status' => self::STATUS_QUEUED,
                    'worker_id' => null,
                    'next_attempt_at' => $retryAt,
                    'claimed_at' => null,
                    'lease_expires_at' => null,
                    'started_at' => null,
                    'failed_at' => null,
                    'last_error_code' => 'worker_lease_expired',
                    'last_error_message' => 'Worker heartbeat lease expired; retry scheduled.',
                ])->save();

                $this->recordWorkerEvent($job, 'inventory.restock_document_job.lease_recovered', [
                    'worker_id' => $previousWorker,
                    'attempt_count' => (int) $job->attempt_count,
                    'next_attempt_at' => $retryAt->toIso8601String(),
                ]);

                continue;
            }

            $job->forceFill([
                'status' => self::STATUS_FAILED,
                'lease_expires_at' => null,
                'next_attempt_at' => null,
                'failed_at' => $now,
                'last_error_code' => 'worker_lease_exhausted',
                'last_error_message' => 'Worker heartbeat lease expired and max attempts were exhausted.',
            ])->save();

            $this->recordWorkerEvent($job, 'inventory.restock_document_job.failed', [
                'worker_id' => $previousWorker,
                'attempt_count' => (int) $job->attempt_count,
                'error_code' => 'worker_lease_exhausted',
            ]);
        }
    }

    private function maxAttempts(): int
    {
        return max(1, min(10, (int) config('services.blue_label_worker.max_attempts', 3)));
    }

    private function retryDelaySeconds(int $attemptCount): int
    {
        $base = max(5, min(3600, (int) config('services.blue_label_worker.retry_base_seconds', 30)));
        $multiplier = 2 ** max(0, min(6, $attemptCount - 1));

        return min(3600, $base * $multiplier);
    }

    private function isRetryableError(string $errorCode): bool
    {
        return in_array($errorCode, [
            'dispatch_failure',
            'worker_crash',
            'heartbeat_failure',
        ], true);
    }

    private function leaseSeconds(): int
    {
        return max(30, min(900, (int) config('services.blue_label_worker.lease_seconds', 180)));
    }

    private function recordWorkerEvent(
        WarehouseRestockDocumentJob $job,
        string $event,
        array $after,
    ): void {
        $this->audit->record(
            AuditActor::system(AuditSource::InternalSystem),
            $event,
            $job::class,
            $job->id,
            after: [
                'reference' => $job->reference,
                'status' => $job->status,
                ...$after,
            ],
            reason: 'blue_label_worker_protocol',
        );
    }

    public function forPlanId(?int $planId): ?array
    {
        if (! $planId) return null;

        $job = WarehouseRestockDocumentJob::query()
            ->where('restock_plan_id', $planId)
            ->where('document_kind', self::DOCUMENT_BLUE_LABELS)
            ->first();

        return $job ? $this->payload($job) : null;
    }

    public function payload(WarehouseRestockDocumentJob $job): array
    {
        $artifactProgress = $this->packages->artifactProgress($job);
        $progressPercent = match ($job->status) {
            self::STATUS_QUEUED => 10,
            self::STATUS_CLAIMED => 25,
            self::STATUS_RUNNING => $artifactProgress['expected'] > 0
                ? 40 + (int) floor(45 * ($artifactProgress['received'] / $artifactProgress['expected']))
                : 40,
            self::STATUS_SUCCEEDED => 100,
            self::STATUS_FAILED => 100,
            default => 0,
        };

        $statusDetail = match ($job->status) {
            self::STATUS_RUNNING => $artifactProgress['received'] <= 0
                ? '正在生成 BTW / PDF'
                : ($artifactProgress['received'] < $artifactProgress['expected']
                    ? '正在回传生成文件 '.$artifactProgress['received'].' / '.$artifactProgress['expected']
                    : '文件已回传，正在生成 PI / ZIP'),
            default => $job->status === self::STATUS_QUEUED
                && (int) $job->attempt_count > 0
                && $job->next_attempt_at !== null
                    ? '上一次执行未完成，系统将自动重试'
                    : $this->statusDetail($job->status),
        };

        return [
            'id' => (int) $job->id,
            'reference' => $job->reference,
            'document_kind' => $job->document_kind,
            'status' => $job->status,
            'status_label' => $this->statusLabel($job->status),
            'status_detail' => $statusDetail,
            'progress_percent' => $progressPercent,
            'received_artifact_files' => $artifactProgress['received'],
            'expected_artifact_files' => $artifactProgress['expected'],
            'last_heartbeat_at' => $job->last_heartbeat_at?->toIso8601String(),
            'attempt_count' => (int) $job->attempt_count,
            'next_attempt_at' => $job->next_attempt_at?->toIso8601String(),
            'is_retry_scheduled' => $job->status === self::STATUS_QUEUED
                && (int) $job->attempt_count > 0
                && $job->next_attempt_at !== null,
            'requested_at' => $job->requested_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'failed_at' => $job->failed_at?->toIso8601String(),
            'last_error_message' => $job->last_error_message,
            'is_terminal' => in_array(
                $job->status,
                [self::STATUS_SUCCEEDED, self::STATUS_FAILED],
                true,
            ),
            'artifact_count' => is_array($job->result_manifest)
                ? count((array) ($job->result_manifest['artifacts'] ?? []))
                : 0,
            'has_pi' => $job->status === self::STATUS_SUCCEEDED
                && Storage::disk('local')->exists($this->packages->piPath($job)),
            'has_package' => $job->status === self::STATUS_SUCCEEDED
                && Storage::disk('local')->exists($this->packages->packagePath($job)),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => '已排队',
            self::STATUS_CLAIMED => '执行节点已领取',
            self::STATUS_RUNNING => '生成中',
            self::STATUS_SUCCEEDED => '已完成',
            self::STATUS_FAILED => '失败',
            default => $status,
        };
    }

    private function statusDetail(string $status): string
    {
        return match ($status) {
            self::STATUS_QUEUED => '等待公司 Windows 执行节点领取',
            self::STATUS_CLAIMED => '任务已被执行节点锁定',
            self::STATUS_RUNNING => '正在生成 BTW / PDF',
            self::STATUS_SUCCEEDED => '蓝标文件已生成',
            self::STATUS_FAILED => '任务失败，等待人工检查或后续重试',
            default => '状态待确认',
        };
    }

    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        ));
    }
}

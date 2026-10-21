<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Kol\Application\AffiliateWp\AffiliateSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\CouponSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\PayoutSnapshotSyncService;
use App\Modules\Kol\Application\AffiliateWp\ReferralCommissionSyncService;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\ConnectorException;
use App\Modules\Sync\Application\EmailTemplateSnapshotReader;
use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Application\SyncRunItemStatus;
use App\Modules\Sync\Application\SyncRunService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class SyncWorkspaceController
{
    public function __construct(
        private readonly SyncOrchestrationService $orchestration,
        private readonly SyncRunService $runs,
        private readonly AffiliateSnapshotSyncService $affiliateSync,
        private readonly CouponSnapshotSyncService $couponSync,
        private readonly ReferralCommissionSyncService $referralSync,
        private readonly PayoutSnapshotSyncService $payoutSync,
    ) {}

    public function index(Request $request): Response
    {
        $runs = $this->orchestration->recentRuns(50);
        $byDomain = $runs->groupBy(fn (Model $run): string => $this->domain($run));

        $domains = [
            $this->domainCard('orders', '同步订单', 'WooCommerce / Shopify / ERP', '/sync/orders', $byDomain->get('orders')),
            $this->domainCard('warehouses', '同步仓库', '统一管理仓库 Connector 与库存同步', '/sync/warehouses', $byDomain->get('warehouses')),
            $this->domainCard('kol', '同步网红', 'Instagram / X 平台资料同步', '/sync/kol', $byDomain->get('kol')),
            $this->domainCard('exports', '导出平台订单', '按平台和范围导出订单数据', '/sync/exports', $byDomain->get('exports')),
        ];
        $connectorRuns = $runs->filter(fn (Model $run): bool => $this->isWordPressConnectorRun($run));
        $domains[] = $this->domainCard('wordpress', 'WordPress Connector', '连接、健康检查与 WordPress / AffiliateWP 数据同步', '/sync/wpapi', $connectorRuns);

        $latestWooOrderAutomatic = $runs->first(fn (Model $run): bool =>
            $run->initiated_by === null
            && $run->provider_key === 'woocommerce'
            && $run->run_type === 'woocommerce_order_incremental'
        );

        return Inertia::render('Sync/Index', [
            'domains' => $domains,
            'queueSummary' => $this->orchestration->queueSummary(),
            'wooOrderSync' => [
                'enabled' => (bool) config('services.woocommerce.order_sync_enabled', false),
                'frequency' => '每 1 小时',
                'can_operate' => (bool) $request->user()?->can('sync.operate'),
                'manual_action' => '/sync/woocommerce/incremental',
                'advanced_href' => '/sync/woocommerce',
                'latest_run' => $latestWooOrderAutomatic ? $this->runData($latestWooOrderAutomatic) : null,
            ],
            'manualRuns' => $runs->filter(fn (Model $run): bool => $run->initiated_by !== null)
                ->take(15)->map(fn (Model $run): array => $this->runData($run))->values()->all(),
            'automaticRuns' => $runs->filter(fn (Model $run): bool => $run->initiated_by === null)
                ->take(15)->map(fn (Model $run): array => $this->runData($run))->values()->all(),
        ]);
    }

    public function wordpressConnector(Request $request, AffiliateWpGateway $gateway, EmailTemplateSnapshotReader $emailTemplateReader): Response
    {
        $settings = DB::table('wordpress_connector_settings')->where('id', 1)->first();
        $recent = $this->orchestration->recentRuns(100);
        $health = $this->decodeHealthPayload($settings?->last_health_payload ?? null);
        $types = [
            'subscribers' => 'wordpress_subscribers_snapshot',
            'affiliates' => 'wordpress_affiliates_snapshot',
            'coupons' => 'wordpress_coupons_snapshot',
            'referrals' => 'wordpress_referrals_snapshot',
            'payouts' => 'wordpress_payouts_snapshot',
        ];
        $resourceRuns = [];
        foreach ($types as $resource => $runType) {
            $run = $recent->first(fn (Model $candidate): bool => $candidate->run_type === $runType);
            $resourceRuns[$resource] = $run ? $this->runData($run) : null;
        }
        $user = $request->user();
        $descriptor = $gateway->descriptor();
        $emailTemplates = [
            'status' => 'unconfigured',
            'read_only' => true,
            'resource_contract_version' => EmailTemplateSnapshotReader::ResourceContractVersion,
            'count' => 0,
            'items' => [],
        ];
        if (! empty($descriptor['configured'])) {
            try {
                $emailTemplates = $emailTemplateReader->read();
            } catch (ConnectorException $exception) {
                $emailTemplates = [
                    ...$emailTemplates,
                    'status' => 'error',
                    'error_code' => $exception->errorCode,
                    'message' => 'Email OS Published Snapshot is temporarily unavailable.',
                ];
            } catch (Throwable) {
                $emailTemplates = [
                    ...$emailTemplates,
                    'status' => 'error',
                    'error_code' => 'CONSUMER_ERROR',
                    'message' => 'Email OS Published Snapshot is temporarily unavailable.',
                ];
            }
        }

        return Inertia::render('Sync/WpApi', [
            'connection' => [
                ...$descriptor,
                'verify_tls' => (bool) config('services.andy_core.verify_tls', true),
                'secret_set' => trim((string) config('services.andy_core.shared_secret')) !== '',
            ],
            'health' => [
                'status' => $settings?->last_health_status,
                'checked_at' => $settings?->last_health_checked_at,
                'data' => $health,
            ],
            'runs' => $resourceRuns,
            'emailTemplates' => $emailTemplates,
            'permissions' => [
                'can_manage_connection' => (bool) $user?->can('sync.operate'),
                'can_sync_subscribers' => (bool) ($user?->can('sync.operate') && $user?->can('marketing.operate')),
                'can_sync_kol' => (bool) ($user?->can('sync.operate') && $user?->can('kol.affiliates.manage')),
                'can_sync_payouts' => (bool) ($user?->can('sync.operate') && $user?->can('kol.payouts.manage')),
            ],
        ]);
    }

    public function saveWordPressConnector(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_url' => ['required', 'string', 'url', 'max:255'],
            'connection_key' => ['required', 'string', 'max:191'],
            'key_id' => ['required', 'string', 'max:191'],
            'shared_secret' => ['nullable', 'string', 'max:2048'],
            'verify_tls' => ['required', 'boolean'],
        ]);

        $this->assertSafeConnectorOrigin($data['site_url']);
        $existing = DB::table('wordpress_connector_settings')->where('id', 1)->first();
        $values = [
            'site_url' => rtrim(trim($data['site_url']), '/'),
            'connection_key' => trim($data['connection_key']),
            'key_id' => trim($data['key_id']),
            'verify_tls' => (bool) $data['verify_tls'],
            'updated_at' => now(),
        ];
        if (! $existing) {
            $values['id'] = 1;
            $values['created_at'] = now();
        }
        if (trim((string) ($data['shared_secret'] ?? '')) !== '') {
            $values['shared_secret'] = Crypt::encryptString((string) $data['shared_secret']);
        }

        DB::table('wordpress_connector_settings')->updateOrInsert(['id' => 1], $values);

        return back()->with('success', 'WordPress Connector 连接配置已保存。');
    }

    public function testWordPressConnector(AffiliateWpGateway $gateway): RedirectResponse
    {
        try {
            $response = $gateway->health();
            $data = is_array($response->data) ? $response->data : [];
            $payload = $this->safeHealthPayload($data, [
                'contract_version' => $response->contractVersion,
                'request_id' => $response->requestId,
                'connection_key' => $response->connectionKey,
            ]);
            $this->storeHealthResult($response->ok ? 'connected' : 'error', $payload);

            return back()->with($response->ok ? 'success' : 'error', $response->ok ? 'WordPress Connector 连接正常。' : 'WordPress Connector 返回非正常状态。');
        } catch (ConnectorException $exception) {
            $this->storeHealthResult('error', [
                'error_code' => $exception->errorCode,
                'http_status' => $exception->httpStatus,
                'message' => $exception->getMessage(),
            ]);

            return back()->with('error', '连接测试失败：'.$exception->getMessage());
        } catch (Throwable $exception) {
            $this->storeHealthResult('error', [
                'error_code' => 'CONNECTOR_ERROR',
                'message' => $exception->getMessage(),
            ]);

            return back()->with('error', '连接测试失败：'.$exception->getMessage());
        }
    }

    public function syncWordPressAffiliates(Request $request, AffiliateWpGateway $gateway): RedirectResponse
    {
        return $this->runWordPressKolSnapshot(
            'affiliates',
            $request,
            $gateway,
            fn (string $connection, $actor): array => $this->affiliateSync->sync($connection, $actor),
        );
    }

    public function syncWordPressCoupons(Request $request, AffiliateWpGateway $gateway): RedirectResponse
    {
        return $this->runWordPressKolSnapshot(
            'coupons',
            $request,
            $gateway,
            fn (string $connection, $actor): array => $this->couponSync->sync($connection, $actor),
        );
    }

    public function syncWordPressReferrals(Request $request, AffiliateWpGateway $gateway): RedirectResponse
    {
        return $this->runWordPressKolSnapshot(
            'referrals',
            $request,
            $gateway,
            fn (string $connection, $actor): array => $this->referralSync->sync($connection, $actor),
        );
    }

    public function syncWordPressPayouts(Request $request, AffiliateWpGateway $gateway): RedirectResponse
    {
        return $this->runWordPressKolSnapshot(
            'payouts',
            $request,
            $gateway,
            fn (string $connection, $actor): array => $this->payoutSync->sync($connection, $actor),
        );
    }

    public function warehouses(): Response
    {
        $warehouses = Warehouse::query()->orderBy('priority')->orderBy('id')->get();

        return Inertia::render('Sync/Warehouses', [
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'country_code' => $warehouse->country_code,
                'is_active' => $warehouse->is_active,
                'connector_status' => 'not_configured',
                'last_sync_at' => null,
            ])->values()->all(),
        ]);
    }

    public function kol(): Response
    {
        return Inertia::render('Sync/Kol', [
            'platforms' => [
                ['key' => 'instagram', 'label' => 'Instagram', 'status' => 'not_configured', 'last_sync_at' => null],
                ['key' => 'x', 'label' => 'X', 'status' => 'not_configured', 'last_sync_at' => null],
            ],
        ]);
    }

    public function exports(Request $request): Response
    {
        $canCustomerExport = $request->user()->can('customers.export') && $request->user()->can('system.permissions.manage');

        $exports = [
            ['key' => 'all', 'label' => '全部 ERP 订单', 'href' => '/orders/export'],
            ['key' => 'procurement', 'label' => '外采订单', 'href' => '/orders/procurement/export'],
        ];

        if ($canCustomerExport) {
            $exports[] = ['key' => 'customers', 'label' => '客户订单', 'href' => '/customers/orders/export'];
        }

        return Inertia::render('Sync/Exports', ['exports' => $exports]);
    }
    /** @param callable(string,mixed):array<string,mixed> $sync */
    private function runWordPressKolSnapshot(
        string $resource,
        Request $request,
        AffiliateWpGateway $gateway,
        callable $sync,
    ): RedirectResponse {
        $actor = $request->user();
        $descriptor = $gateway->descriptor();
        $connection = trim((string) ($descriptor['connection_key'] ?? '')) ?: null;
        $run = $this->runs->start(
            'wordpress_'.$resource.'_snapshot',
            0,
            $actor,
            'affiliatewp',
            $connection,
            ['domain' => 'kol', 'resource' => $resource, 'surface' => 'wordpress_connector'],
        );

        try {
            if (! ($descriptor['configured'] ?? false) || $connection === null) {
                throw new \RuntimeException('Andy Core WordPress connector is not configured.');
            }
            $result = $sync($connection, $actor);
            $references = array_values(array_unique(array_map(
                static fn ($value): string => trim((string) $value),
                is_array($result['provider_references'] ?? null) ? $result['provider_references'] : [],
            )));
            $references = array_values(array_filter($references, static fn (string $value): bool => $value !== ''));
            $this->runs->setRequestedItems($run, count($references));
            $safeResult = [];
            foreach (['synced', 'marked_missing', 'affiliate_unresolved', 'order_unresolved', 'kol_unresolved'] as $key) {
                if (isset($result[$key]) && is_numeric($result[$key])) {
                    $safeResult[$key] = (int) $result[$key];
                }
            }
            $this->runs->annotateContext($run, ['fetched_items' => count($references), 'result' => $safeResult]);
            foreach ($references as $reference) {
                $item = $this->runs->addItem($run, [
                    'operation' => 'wordpress.'.$resource.'_import',
                    'provider_reference' => $reference,
                ]);
                $this->runs->markProcessing($item);
                $this->runs->finishItem($item, SyncRunItemStatus::Succeeded, 'imported');
            }
            $completed = $this->runs->complete($run, $actor);
            $label = ['affiliates' => 'Affiliate', 'coupons' => 'Coupon', 'referrals' => 'Referral', 'payouts' => 'Payout'][$resource] ?? $resource;

            return back()->with('success', sprintf('%s 同步完成：%d 条。', $label, (int) $completed->succeeded_items));
        } catch (Throwable $exception) {
            $this->runs->setRequestedItems($run, 1);
            $item = $this->runs->addItem($run, [
                'operation' => 'wordpress.'.$resource.'_snapshot',
                'provider_reference' => null,
            ]);
            $this->runs->markProcessing($item);
            $this->runs->finishItem($item, SyncRunItemStatus::Failed, 'provider_error', 'provider_error', $exception->getMessage());
            $this->runs->annotateContext($run, ['fetched_items' => 0]);
            $this->runs->complete($run, $actor);
            report($exception);

            return back()->with('error', ucfirst($resource).' 同步失败。请检查 Connector / Provider 状态后重试。');
        }
    }

    private function isWordPressConnectorRun(Model $run): bool
    {
        return str_starts_with((string) $run->run_type, 'wordpress_')
            && str_ends_with((string) $run->run_type, '_snapshot');
    }

    private function domain(Model $run): string
    {
        $explicit = $run->context['domain'] ?? null;
        if (in_array($explicit, ['orders', 'warehouses', 'kol', 'exports', 'marketing'], true)) {
            return $explicit;
        }

        return in_array($run->provider_key, ['woocommerce', 'shopify', 'erp'], true) ? 'orders' : 'orders';
    }

    private function domainCard(string $key, string $title, string $subtitle, string $href, $runs): array
    {
        $latest = $runs?->first();

        return [
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'href' => $href,
            'last_sync_at' => $latest?->completed_at?->toIso8601String() ?? $latest?->started_at?->toIso8601String(),
            'status' => $latest?->status?->value ?? 'not_started',
            'succeeded_items' => (int) ($latest?->succeeded_items ?? 0),
            'failed_items' => (int) ($latest?->failed_items ?? 0),
            'conflict_items' => (int) ($latest?->conflict_items ?? 0),
        ];
    }

    private function runData(Model $run): array
    {
        return [
            'id' => $run->id,
            'domain' => $this->domain($run),
            'run_type' => $run->run_type,
            'provider_key' => $run->provider_key,
            'status' => $run->status->value,
            'initiator' => $run->initiator?->name,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'requested_items' => (int) $run->requested_items,
            'succeeded_items' => (int) $run->succeeded_items,
            'failed_items' => (int) $run->failed_items,
            'conflict_items' => (int) $run->conflict_items,
        ];
    }

    private function assertSafeConnectorOrigin(string $url): void
    {
        $parsed = parse_url(rtrim(trim($url), '/'));
        $invalid = $parsed === false || empty($parsed['scheme']) || empty($parsed['host'])
            || ! in_array($parsed['scheme'], ['http', 'https'], true)
            || ! empty($parsed['user']) || ! empty($parsed['pass'])
            || ! empty($parsed['query']) || ! empty($parsed['fragment'])
            || (! empty($parsed['path']) && $parsed['path'] !== '/');

        if ($invalid) {
            throw ValidationException::withMessages(['site_url' => '请输入不含路径、账号、查询参数的 WordPress 站点地址。']);
        }
        if (! in_array(config('app.env', 'production'), ['local', 'testing'], true) && $parsed['scheme'] !== 'https') {
            throw ValidationException::withMessages(['site_url' => '非本地环境必须使用 HTTPS。']);
        }
    }
    /** @return array<string,mixed> */
    private function safeHealthPayload(array $data, array $envelope): array
    {
        $keys = [
            'andy_core_version', 'wp_version', 'woocommerce_active', 'woocommerce_version',
            'affiliatewp_active', 'affiliatewp_version', 'server_time', 'writable', 'operational',
        ];
        $safe = $envelope;
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $safe[$key] = $data[$key];
            }
        }

        return $safe;
    }

    /** @return array<string,mixed>|null */
    private function decodeHealthPayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (! is_string($payload) || $payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
    /** @param array<string,mixed> $payload */
    private function storeHealthResult(string $status, array $payload): void
    {
        $existing = DB::table('wordpress_connector_settings')->where('id', 1)->first();
        $values = [
            'last_health_checked_at' => now(),
            'last_health_status' => $status,
            'last_health_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];
        if (! $existing) {
            $values += [
                'id' => 1,
                'site_url' => trim((string) config('services.andy_core.url')) ?: null,
                'connection_key' => trim((string) config('services.andy_core.connection_key')) ?: 'beyourlover.com',
                'key_id' => trim((string) config('services.andy_core.key_id')) ?: null,
                'verify_tls' => (bool) config('services.andy_core.verify_tls', true),
                'created_at' => now(),
            ];
        }

        DB::table('wordpress_connector_settings')->updateOrInsert(['id' => 1], $values);
    }
}

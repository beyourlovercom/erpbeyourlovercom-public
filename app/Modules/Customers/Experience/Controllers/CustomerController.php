<?php

namespace App\Modules\Customers\Experience\Controllers;

use App\Modules\Audit\Application\CustomerActivityProjection;
use App\Modules\Customers\Application\CustomerService;
use App\Modules\Customers\Application\CustomerGroupService;
use App\Modules\Customers\Application\CustomerSubscriberProjection;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Customers\Domain\CountryCodeNormalizer;
use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;
use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Customers\Infrastructure\Models\CustomerAddress;
use App\Modules\Customers\Infrastructure\Models\CustomerImportBatch;
use App\Modules\Customers\Application\CustomerImportService;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Logistics\Application\CustomerShipmentProjection;
use App\Modules\Orders\Application\CustomerOrderProjection;
use App\Modules\Shell\Application\UserUiPreferenceService;
use App\Modules\Support\Application\CustomerSupportProjection;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class CustomerController
{
    private const LIBRARY_GRID_NAMESPACE = 'grid.customers.library.v1';
    private const GROUPS_GRID_NAMESPACE = 'grid.customers.groups.v1';




    private const SOURCES = [
        'manual' => '手工录入',
        'website' => '网站',
        'woocommerce' => 'WooCommerce',
        'csv_import' => 'CSV 导入',
        'external_platform' => '其他平台',
    ];

    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerGroupService $customerGroups,
        private readonly CustomerSubscriberProjection $subscriberProjection,
        private readonly FxReferenceSettingsService $fx,
        private readonly UserUiPreferenceService $uiPreferences,
        private readonly CustomerSupportProjection $supportProjection,
        private readonly CustomerShipmentProjection $shipmentProjection,
        private readonly CustomerImportService $customerImport,
        private readonly CustomerOrderProjection $orderProjection,
        private readonly CustomerActivityProjection $activityProjection,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->routeIs('customers.groups') ? 'groups' : 'library';
        $gridPreferenceNamespace = $workspace === 'groups' ? self::GROUPS_GRID_NAMESPACE : self::LIBRARY_GRID_NAMESPACE;
        $filters = $request->only(['search', 'country_code', 'source_type', 'archived', 'group']);
        $paginator = $this->customers->list($filters);
        $groupCountFilters = array_diff_key($filters, ['group' => true]);
        $systemGroupCounts = $workspace === 'groups' ? collect([
            '' => '全部客户',
            'no_purchase' => '无购买客户',
            'new_customer' => '新客户',
            'repeat_customer' => '复购客户',
            'multi_product_single_order' => '单次多产品客户',
            'has_shipment' => '已发货客户',
            'no_shipment_yet' => '尚未发货客户',
            'active_customer' => '活跃客户',
            'churn_risk' => '流失风险客户',
            'dormant_customer' => '沉睡客户',
            'high_frequency' => '高频客户',
            'high_value' => '高价值客户',
            'vip' => 'VIP客户',
        ])->mapWithKeys(fn (string $label, string $group): array => [
            $group => $this->customers->count([...$groupCountFilters, 'group' => $group]),
        ])->all() : [];
        $manualGroups = $this->customerGroups->all();
        $selectedManualGroup = $this->customerGroups->findForFilter($filters['group'] ?? null);
        $subscriberProjections = $this->subscriberProjection->forCustomers($paginator->items());

        $customerIds = collect($paginator->items())->pluck('id');
        $supportCounts = $this->supportProjection->pendingCountsForCustomers($customerIds);
        $shipmentCounts = $this->shipmentProjection->countsForCustomers($customerIds);

        $latestOrderDates = $customerIds->isNotEmpty()
            ? DB::table('orders')
                ->whereIn('customer_id', $customerIds)
                ->where('order_kind', 'customer')
                ->select('customer_id')
                ->selectRaw('MAX(order_date) as latest_order_date')
                ->groupBy('customer_id')
                ->get()->keyBy('customer_id')
            : collect();

        $lifetimeRevenueBatch = $customerIds->isNotEmpty()
            ? DB::table('orders')
                ->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->whereIn('orders.customer_id', $customerIds)
                ->where('orders.order_kind', 'customer')
                ->select('orders.customer_id')
                ->selectRaw('COALESCE(orders.currency, \'USD\') as currency')
                ->selectRaw('SUM(order_items.line_revenue) as revenue')
                ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
                ->groupBy('orders.customer_id', 'currency')
                ->get()->groupBy('customer_id')
            : collect();

        $now = now();
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd = $now->copy()->endOfMonth()->toDateString();

        $totalCustomers = DB::table('customers')->whereNull('archived_at')->count();
        $newThisMonth = DB::table('customers')
            ->whereNull('archived_at')
            ->whereDate('created_at', '>=', $monthStart)
            ->whereDate('created_at', '<=', $monthEnd)
            ->count();
        $ordersThisMonth = DB::table('orders')
            ->where('order_kind', 'customer')
            ->whereNotNull('customer_id')
            ->whereDate('order_date', '>=', $monthStart)
            ->whereDate('order_date', '<=', $monthEnd)
            ->count();
        $revenueThisMonth = DB::table('orders')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.order_kind', 'customer')
            ->whereNotNull('orders.customer_id')
            ->whereDate('orders.order_date', '>=', $monthStart)
            ->whereDate('orders.order_date', '<=', $monthEnd)
            ->selectRaw('COALESCE(orders.currency, \'USD\') as currency')
            ->selectRaw('COALESCE(SUM(order_items.line_revenue), 0) as revenue')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn (object $row): array => ['currency' => $row->currency, 'revenue' => (float) $row->revenue])
            ->values()->all();

        return Inertia::render('Customers/Library/Index', [
            'customers' => $paginator->through(function (Customer $customer) use ($latestOrderDates, $lifetimeRevenueBatch, $subscriberProjections, $supportCounts, $shipmentCounts): array {
                $data = $this->customerData($customer);
                $data['subscription'] = $subscriberProjections[$this->normalizeEmail($customer->email)] ?? ['matched' => false, 'subscriber_email' => null, 'subscription_facts' => [], 'subscription_statuses' => []];
                $latestDate = $latestOrderDates->get($customer->id);
                $data['latest_order_date'] = $latestDate ? substr((string) $latestDate->latest_order_date, 0, 10) : null;
                $revRows = $lifetimeRevenueBatch->get($customer->id) ?? collect();
                $data['lifetime_revenue'] = $revRows->map(function (object $r): array {
                    $orderCount = (int) $r->order_count;
                    $revenue = (float) $r->revenue;
                    return [
                        'currency' => $r->currency,
                        'revenue' => $revenue,
                        'order_count' => $orderCount,
                        'average_order_value' => $orderCount > 0 ? round($revenue / $orderCount, 2) : 0,
                    ];
                })->sortBy('currency')->values()->all();
                $data['primary_address'] = $this->addressData($customer->shippingAddress ?? $customer->billingAddress);
                $data['pending_support_tickets'] = $supportCounts[$customer->id] ?? 0;
                $data['shipment_count'] = $shipmentCounts[$customer->id] ?? 0;
                return $data;
            }),
            'filters' => $filters,
            'sources' => self::SOURCES,
            'stats' => [
                'total_customers' => $totalCustomers,
                'new_this_month' => $newThisMonth,
                'orders_this_month' => $ordersThisMonth,
                'revenue_this_month' => $revenueThisMonth,
                'rmb_estimate' => $this->fx->convertToCny($revenueThisMonth),
                'subscriber_count_available' => true,
                'subscriber_identity_links' => $this->subscriberProjection->identityLinkCount(),
                'pending_support_tickets' => $this->supportProjection->pendingCount(),
            ],
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
            'canImport' => (bool) ($request->user()?->hasPermission('customers.import') && $request->user()?->hasPermission('system.permissions.manage')),
            'canExport' => (bool) ($request->user()?->hasPermission('customers.export') && $request->user()?->hasPermission('system.permissions.manage')),
            'workspace' => $workspace,
            'gridPreferenceNamespace' => $gridPreferenceNamespace,
            'gridPreference' => $request->user() ? $this->uiPreferences->get($request->user(), $gridPreferenceNamespace) : null,
            'manualGroups' => $manualGroups->map(fn ($group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'member_count' => (int) $group->memberships_count,
            ])->values()->all(),
            'systemGroupCounts' => $systemGroupCounts,
            'selectedManualGroup' => $selectedManualGroup ? [
                'id' => $selectedManualGroup->id,
                'name' => $selectedManualGroup->name,
                'description' => $selectedManualGroup->description,
                'member_count' => $selectedManualGroup->memberships()->count(),
            ] : null,
        ]);
    }

    public function groups(Request $request): Response
    {
        return $this->index($request);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000']]);
        $group = $this->customerGroups->create($validated);
        return redirect()->to('/customers/groups?group='.CustomerGroupService::MANUAL_FILTER_PREFIX.$group->id)->with('success', '客户分组已创建');
    }

    public function updateGroup(Request $request, \App\Modules\Customers\Infrastructure\Models\CustomerGroup $customerGroup): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000']]);
        $this->customerGroups->update($customerGroup, $validated);
        return redirect()->to('/customers/groups?group='.CustomerGroupService::MANUAL_FILTER_PREFIX.$customerGroup->id)->with('success', '客户分组已更新');
    }

    public function destroyGroup(\App\Modules\Customers\Infrastructure\Models\CustomerGroup $customerGroup): RedirectResponse
    {
        $this->customerGroups->delete($customerGroup);
        return redirect()->route('customers.groups')->with('success', '客户分组已删除');
    }

    public function searchGroupCustomers(Request $request, \App\Modules\Customers\Infrastructure\Models\CustomerGroup $customerGroup): JsonResponse
    {
        $validated = $request->validate(['search' => ['required', 'string', 'min:1', 'max:100']]);
        return response()->json(['customers' => $this->customerGroups->searchAvailableCustomers($customerGroup, $validated['search'])->map(fn (Customer $customer): array => [
            'id' => $customer->id, 'name' => $customer->name, 'email' => $customer->email, 'phone' => $customer->phone,
        ])->values()->all()]);
    }

    public function addGroupMembers(Request $request, \App\Modules\Customers\Infrastructure\Models\CustomerGroup $customerGroup): RedirectResponse
    {
        $validated = $request->validate(['customer_ids' => ['required', 'array', 'min:1', 'max:100'], 'customer_ids.*' => ['integer', 'distinct', 'exists:customers,id']]);
        $this->customerGroups->addMembers($customerGroup, array_map('intval', $validated['customer_ids']), $request->user());
        return redirect()->to('/customers/groups?group='.CustomerGroupService::MANUAL_FILTER_PREFIX.$customerGroup->id)->with('success', '客户已加入分组');
    }

    public function removeGroupMember(\App\Modules\Customers\Infrastructure\Models\CustomerGroup $customerGroup, Customer $customer): RedirectResponse
    {
        $this->customerGroups->removeMember($customerGroup, $customer);
        return redirect()->to('/customers/groups?group='.CustomerGroupService::MANUAL_FILTER_PREFIX.$customerGroup->id)->with('success', '客户已移出分组');
    }


    public function create(): Response
    {
        return Inertia::render('Customers/Library/Create', [
            'owners' => $this->owners(),
            'sources' => self::SOURCES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeEmailInput($request);
        $validated = $request->validate(array_merge($this->rules(), $this->addressRules('billing'), $this->addressRules('shipping')));
        $customer = DB::transaction(function () use ($validated, $request): Customer {
            $c = $this->customers->create(array_diff_key($validated, array_flip(['billing_address', 'shipping_address'])), $request->user());
            $this->syncAddresses($c, $validated);
            return $c;
        });
        return redirect()->route('customers.library.show', $customer)->with('success', '客户已创建');
    }

    public function show(Request $request, Customer $customer): Response
    {
        $customer->load(['owner', 'billingAddress', 'shippingAddress']);
        $subscription = $this->subscriberProjection->forEmail($customer->email);
        $orders = $this->orderProjection->forCustomer($customer->id);
        $summary = $orders['summary'];
        $revenueByCurrency = $orders['revenue_by_currency'];
        $recentOrders = $orders['recent_orders'];

        $shipmentCount = $this->shipmentProjection->countForCustomer($customer->id);
        $shipments = $this->shipmentProjection->recordsForCustomer($customer->id);

        $recentOrderIds = collect($recentOrders)->pluck('id');
        $orderShipmentStatuses = collect($this->shipmentProjection->statusesForOrders($recentOrderIds));
        $recentOrders = collect($recentOrders)->map(function (array $order) use ($orderShipmentStatuses): array {
            $order['shipment_status'] = $orderShipmentStatuses->get($order['id']);
            return $order;
        })->all();

        $supportTickets = $this->supportProjection->forCustomer($customer->id);
        $supportTicketIds = collect($supportTickets)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $orderCount = (int) ($summary->order_count ?? 0);
        $customer->orders_count = $orderCount;

        $toDate = static fn (?string $v): ?string => $v !== null ? substr($v, 0, 10) : null;

        return Inertia::render('Customers/Library/Show', [
            'customer' => $this->customerData($customer),
            'billingAddress' => $this->addressData($customer->billingAddress),
            'shippingAddress' => $this->addressData($customer->shippingAddress),
            'metrics' => [
                'order_count' => $orderCount,
                'first_order_date' => $toDate($summary->first_order_date ?? null),
                'last_order_date' => $toDate($summary->last_order_date ?? null),
                'revenue_by_currency' => $revenueByCurrency,
                'shipment_count' => $shipmentCount,
            ],
            'systemSegments' => $this->customers->systemSegmentsFor($customer),
            'manualGroups' => $customer->groups()->orderBy('name')->get(['customer_groups.id', 'customer_groups.name', 'customer_groups.description'])->map(fn ($group): array => [
                'id' => $group->id, 'name' => $group->name, 'description' => $group->description,
            ])->values()->all(),
            'recentOrders' => $recentOrders,
            'shipments' => $shipments,
            'supportTickets' => $supportTickets,
            'pendingSupportTickets' => collect($supportTickets)->whereIn('status', CustomerSupportProjection::PENDING_STATUSES)->count(),
            'subscription' => $subscription,
            'audit' => $this->activityProjection->forCustomer($customer->id, $supportTicketIds),
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $customer->load(['billingAddress', 'shippingAddress']);
        return Inertia::render('Customers/Library/Edit', [
            'customer' => $this->customerData($customer),
            'billingAddress' => $this->addressData($customer->billingAddress),
            'shippingAddress' => $this->addressData($customer->shippingAddress),
            'owners' => $this->owners(),
            'sources' => self::SOURCES,
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->normalizeEmailInput($request);
        $validated = $request->validate(array_merge($this->rules($customer), $this->addressRules('billing'), $this->addressRules('shipping')));
        DB::transaction(function () use ($customer, $validated, $request): void {
            $this->customers->update($customer, array_diff_key($validated, array_flip(['billing_address', 'shipping_address'])), $request->user());
            $this->syncAddresses($customer, $validated);
        });
        return redirect()->route('customers.library.show', $customer)->with('success', '客户已更新');
    }

    public function archive(Request $request, Customer $customer): RedirectResponse
    {
        $this->customers->archive($customer, $request->user());
        return redirect()->route('customers.library')->with('success', '客户已归档');
    }

    public function restore(Request $request, Customer $customer): RedirectResponse
    {
        $this->customers->restore($customer, $request->user());
        return redirect()->route('customers.library.show', $customer)->with('success', '客户已恢复');
    }

    public function importWorkspace(Request $request): Response
    {
        return Inertia::render('Customers/Import', ['history' => $this->customerImport->history(), 'columns' => $this->customerImport->headers()]);
    }

    public function previewImport(Request $request): JsonResponse
    {
        if (! $request->hasFile('file')) {
            return response()->json(['message' => '请选择导入文件。'], 422);
        }
        $request->validate(['file' => ['file']]);
        $batch = $this->customerImport->preview($request->file('file'), $request->user());
        return response()->json(['batch' => $batch->load('rows'), 'stats' => ['total'=>$batch->preview_total_rows,'create'=>$batch->preview_create_rows,'update'=>$batch->preview_update_rows,'unchanged'=>$batch->preview_unchanged_rows,'warning'=>$batch->preview_warning_rows,'error'=>$batch->preview_error_rows]]);
    }

    public function confirmImport(Request $request, CustomerImportBatch $batch): JsonResponse
    {
        return response()->json(['batch' => $this->customerImport->confirm($batch, $request->user())]);
    }

    public function reservedImport(Request $request): JsonResponse
    {
        return $this->previewImport($request);
    }

    public function importTemplate(Request $request): SymfonyResponse
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        if ($format === 'xlsx') return response($this->customerImport->xlsx([]), 200, ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Content-Disposition'=>'attachment; filename="customer-import-template.xlsx"']);
        return response("\xEF\xBB\xBF".implode(',', $this->customerImport->headers())."\r\n", 200, ['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="customer-import-template.csv"']);
    }

    public function reservedExport(Request $request): SymfonyResponse
    {
        $rows = $this->customerImport->exportRows($request->only(['search','country_code','source_type','archived','group']));
        $format = strtolower((string) $request->query('format', 'xlsx'));
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($rows): void { echo "\xEF\xBB\xBF"; $h=fopen('php://output','w'); fputcsv($h,$this->customerImport->headers()); foreach($rows as $row) fputcsv($h,array_map(fn($c)=>$row[$c]??null,$this->customerImport->headers())); fclose($h); }, 'customers.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
        }
        return response($this->customerImport->xlsx($rows), 200, ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','Content-Disposition'=>'attachment; filename="customers.xlsx"']);
    }

    private function syncAddresses(Customer $customer, array $validated): void
    {
        foreach (['billing', 'shipping'] as $type) {
            $key = $type.'_address';
            if (! isset($validated[$key]) || ! is_array($validated[$key])) {
                continue;
            }
            $data = $validated[$key];
            $hasContent = collect($data)->filter(fn ($v): bool => $v !== null && $v !== '')->isNotEmpty();
            if ($hasContent) {
                CustomerAddress::query()->updateOrCreate(
                    ['customer_id' => $customer->id, 'address_type' => $type],
                    $data,
                );
            } else {
                CustomerAddress::query()
                    ->where('customer_id', $customer->id)
                    ->where('address_type', $type)
                    ->delete();
            }
        }
    }

    private function customerData(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'customer_number' => $customer->customer_number,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'country_code' => $customer->country_code,
            'language_code' => $customer->language_code,
            'source_type' => $customer->source_type,
            'source_label' => self::SOURCES[$customer->source_type] ?? $customer->source_type,
            'owner' => $customer->owner ? ['id' => $customer->owner->id, 'name' => $customer->owner->name] : null,
            'notes' => $customer->notes,
            'orders_count' => (int) ($customer->orders_count ?? 0),
            'archived_at' => $customer->archived_at?->toIso8601String(),
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }

    private function addressData(?CustomerAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }
        return [
            'name' => $address->name,
            'company' => $address->company,
            'phone' => $address->phone,
            'country_code' => $address->country_code,
            'province_state' => $address->province_state,
            'city' => $address->city,
            'address_line_1' => $address->address_line_1,
            'address_line_2' => $address->address_line_2,
            'postal_code' => $address->postal_code,
        ];
    }

    private function rules(?Customer $customer = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customer?->id)],
            'phone' => ['nullable', 'string', 'max:64'],
            'country_code' => ['nullable', 'string', 'max:8', $this->countryRule()],
            'language_code' => ['nullable', 'string', 'max:16'],
            'source_type' => ['required', Rule::in(array_keys(self::SOURCES))],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function addressRules(string $type): array
    {
        $prefix = $type.'_address';
        return [
            "{$prefix}" => ['nullable', 'array'],
            "{$prefix}.name" => ['nullable', 'string', 'max:255'],
            "{$prefix}.company" => ['nullable', 'string', 'max:255'],
            "{$prefix}.phone" => ['nullable', 'string', 'max:64'],
            "{$prefix}.country_code" => ['nullable', 'string', 'max:2'],
            "{$prefix}.province_state" => ['nullable', 'string', 'max:128'],
            "{$prefix}.city" => ['nullable', 'string', 'max:128'],
            "{$prefix}.address_line_1" => ['nullable', 'string', 'max:255'],
            "{$prefix}.address_line_2" => ['nullable', 'string', 'max:255'],
            "{$prefix}.postal_code" => ['nullable', 'string', 'max:32'],
        ];
    }

    private function countryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') return;
            try { CountryCodeNormalizer::normalize($value); }
            catch (InvalidCompanyAttributeException) { $fail('国家代码需为两位字母。'); }
        };
    }

    private function normalizeEmailInput(Request $request): void
    {
        if (! $request->exists('email')) {
            return;
        }
        $email = trim((string) $request->input('email'));
        $request->merge(['email' => $email === '' ? null : strtolower($email)]);
    }

    private function normalizeEmail(?string $email): string
    {
        return $email === null ? '' : strtolower(trim($email));
    }

    private function owners(): array
    {
        return User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->toArray();
    }
}

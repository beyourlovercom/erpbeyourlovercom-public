<?php

namespace App\Modules\Customers\Experience\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Customers\Application\CompanyService;
use App\Modules\Customers\Domain\CountryCodeNormalizer;
use App\Modules\Customers\Domain\Exceptions\InvalidCompanyAttributeException;
use App\Modules\Customers\Domain\WebsiteDomainNormalizer;
use App\Modules\Customers\Infrastructure\Models\Company;
use App\Modules\Identity\Infrastructure\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CompanyController
{
    public function __construct(private readonly CompanyService $companies) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'country_code', 'owner_id', 'archived']);
        $companies = $this->companies->list($filters);

        return Inertia::render('Customers/Companies/Index', [
            'companies' => $companies->map(fn (Company $company): array => $this->companyData($company))->values()->all(),
            'filters' => $filters,
            'owners' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Customers/Companies/Create', [
            'owners' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $company = $this->companies->create($data, $request->user());

        return redirect()->route('customers.companies.show', $company)->with('success', '公司已创建');
    }

    public function show(Request $request, Company $company): Response
    {
        $company->load('owner');

        return Inertia::render('Customers/Companies/Show', [
            'company' => $this->companyData($company),
            'canOperate' => (bool) $request->user()?->hasPermission('customers.operate'),
            'audit' => AuditRecord::query()
                ->where('entity_type', Company::class)
                ->where('entity_id', (string) $company->getKey())
                ->latest('created_at')->limit(30)->get()
                ->map(fn (AuditRecord $record): array => [
                    'action' => $record->action,
                    'actor_id' => $record->actor_id,
                    'created_at' => $record->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('Customers/Companies/Edit', [
            'company' => $this->companyData($company),
            'owners' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate($this->updateRules());
        $this->companies->update($company, $data, $request->user());

        return redirect()->route('customers.companies.show', $company)->with('success', '公司已更新');
    }

    public function archive(Request $request, Company $company): RedirectResponse
    {
        $this->companies->archive($company, $request->user());

        return redirect()->route('customers.companies')->with('success', '公司已归档');
    }

    public function restore(Request $request, Company $company): RedirectResponse
    {
        $this->companies->restore($company, $request->user());

        return redirect()->route('customers.companies.show', $company)->with('success', '公司已取消归档');
    }

    private function companyData(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'website_url' => $company->website_url,
            'website_domain_normalized' => $company->website_domain_normalized,
            'country_code' => $company->country_code,
            'registration_number' => $company->registration_number,
            'industry' => $company->industry,
            'owner' => $company->owner ? ['id' => $company->owner->id, 'name' => $company->owner->name] : null,
            'status' => $company->status,
            'archived_at' => $company->archived_at?->toIso8601String(),
            'created_at' => $company->created_at?->toIso8601String(),
            'updated_at' => $company->updated_at?->toIso8601String(),
        ];
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'max:500', $this->websiteRule()],
            'country_code' => ['nullable', 'string', 'max:8', $this->countryRule()],
            'registration_number' => ['nullable', 'string', 'max:191'],
            'industry' => ['nullable', 'string', 'max:191'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    private function updateRules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:500', $this->websiteRule()],
            'country_code' => ['sometimes', 'nullable', 'string', 'max:8', $this->countryRule()],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:191'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:191'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    private function websiteRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            try {
                WebsiteDomainNormalizer::normalize($value);
            } catch (InvalidCompanyAttributeException) {
                $fail('网址无效，请提供包含协议（如 https://）的有效网址。');
            }
        };
    }

    private function countryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            try {
                CountryCodeNormalizer::normalize($value);
            } catch (InvalidCompanyAttributeException) {
                $fail('国家代码需为两位字母。');
            }
        };
    }
}

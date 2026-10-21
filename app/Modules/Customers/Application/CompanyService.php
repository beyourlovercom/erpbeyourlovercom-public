<?php

namespace App\Modules\Customers\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Customers\Domain\CountryCodeNormalizer;
use App\Modules\Customers\Domain\WebsiteDomainNormalizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CompanyService
{
    private const AUDIT_FIELDS = [
        'name', 'legal_name', 'website_url', 'website_domain_normalized',
        'country_code', 'registration_number', 'industry', 'owner_id', 'status',
    ];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function list(array $filters = []): Collection
    {
        $companyClass = 'App\\Modules\\Customers\\Infrastructure\\Models\\Company';
        $archived = $filters['archived'] ?? '';

        return $companyClass::query()
            ->with('owner')
            ->when($archived === '', fn ($query) => $query->whereNull('archived_at'))
            ->when($archived === 'only', fn ($query) => $query->whereNotNull('archived_at'))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('legal_name', 'like', "%{$search}%")
                        ->orWhere('website_domain_normalized', 'like', "%{$search}%")
                        ->orWhere('registration_number', 'like', "%{$search}%");
                });
            })
            ->when($filters['country_code'] ?? null, fn ($query, string $country) => $query->where('country_code', strtoupper($country)))
            ->when($filters['owner_id'] ?? null, fn ($query, $ownerId) => $query->where('owner_id', $ownerId))
            ->orderBy('name')
            ->get();
    }

    public function find(int|string $id): Model
    {
        $companyClass = 'App\\Modules\\Customers\\Infrastructure\\Models\\Company';

        return $companyClass::query()->with('owner')->findOrFail($id);
    }

    public function create(array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($attributes, $actor): Model {
            $companyClass = 'App\\Modules\\Customers\\Infrastructure\\Models\\Company';
            $company = $companyClass::create([
                ...$this->normalizeAttributes($attributes),
                'status' => 'prospect',
            ]);
            $this->record($actor, 'company.created', $company, after: $this->auditData($company), reason: 'company_created');

            return $company->load('owner');
        });
    }

    public function update(Model $company, array $attributes, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($company, $attributes, $actor): Model {
            $locked = $company->newQuery()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->auditData($locked);
            $locked->fill($this->normalizeAttributes($attributes))->save();
            $after = $this->auditData($locked);
            $changedFields = array_keys(array_diff_assoc($after, $before));
            $this->record(
                $actor,
                'company.updated',
                $locked,
                before: $before,
                after: $after,
                reason: $changedFields === [] ? 'no_material_change' : 'company_corrected',
            );

            return $locked->fresh('owner');
        });
    }

    public function archive(Model $company, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($company, $actor): Model {
            $locked = $company->newQuery()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->archived_at !== null) {
                return $locked;
            }

            $before = $this->auditData($locked);
            $locked->forceFill(['archived_at' => now()])->save();
            $after = $this->auditData($locked);
            $this->record($actor, 'company.archived', $locked, before: $before, after: $after, reason: 'company_archived');

            return $locked->fresh('owner');
        });
    }

    public function restore(Model $company, ?Authenticatable $actor): Model
    {
        return DB::transaction(function () use ($company, $actor): Model {
            $locked = $company->newQuery()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->archived_at === null) {
                return $locked;
            }

            $before = $this->auditData($locked);
            $locked->forceFill(['archived_at' => null])->save();
            $after = $this->auditData($locked);
            $this->record($actor, 'company.restored', $locked, before: $before, after: $after, reason: 'company_restored');

            return $locked->fresh('owner');
        });
    }

    private function normalizeAttributes(array $attributes): array
    {
        if (array_key_exists('website_url', $attributes)) {
            $url = $attributes['website_url'];
            $attributes['website_domain_normalized'] = ($url === null || $url === '')
                ? null
                : WebsiteDomainNormalizer::normalize($url);
        }

        if (array_key_exists('country_code', $attributes)) {
            $code = $attributes['country_code'];
            $attributes['country_code'] = ($code === null || $code === '')
                ? null
                : CountryCodeNormalizer::normalize($code);
        }

        return $attributes;
    }

    private function record(?Authenticatable $actor, string $action, Model $company, ?array $before = null, ?array $after = null, ?string $reason = null): void
    {
        $this->audit->record(
            $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
            $action,
            get_class($company),
            $company->getKey(),
            before: $before,
            after: $after,
            reason: $reason,
        );
    }

    private function auditData(Model $company): array
    {
        $data = [];
        foreach (self::AUDIT_FIELDS as $field) {
            $data[$field] = $company->{$field};
        }
        $data['archived_at'] = $company->archived_at?->toIso8601String();

        return $data;
    }
}

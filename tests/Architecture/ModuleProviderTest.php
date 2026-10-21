<?php

namespace Tests\Architecture;

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Backup\Providers\BackupServiceProvider;
use App\Modules\BusinessProjects\Providers\BusinessProjectsServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Guides\Providers\GuidesServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Kol\Providers\KolServiceProvider;
use App\Modules\Logistics\Providers\LogisticsServiceProvider;
use App\Modules\Marketing\Providers\MarketingServiceProvider;
use App\Modules\NotificationCenter\Providers\NotificationCenterServiceProvider;
use App\Modules\ModuleManifest;
use App\Modules\ModulesServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Runtime\Providers\RuntimeServiceProvider;
use App\Modules\Search\Providers\SearchServiceProvider;
use App\Modules\Shell\Providers\ShellServiceProvider;
use App\Modules\Sync\Providers\SyncServiceProvider;
use App\Modules\Sync\Providers\ManualSyncServiceProvider;
use App\Modules\Products\Providers\ProductsServiceProvider;
use App\Modules\Procurement\Providers\ProcurementServiceProvider;
use App\Modules\Support\Providers\SupportServiceProvider;
use App\Modules\Traffic\Providers\TrafficServiceProvider;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ModuleProviderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/app/Modules/ModuleManifest.php';
        require_once dirname(__DIR__, 2).'/app/Modules/ModulesServiceProvider.php';
    }

    public function test_module_contract_classes_exist_and_have_expected_inheritance(): void
    {
        self::assertTrue(class_exists(ModuleManifest::class));
        self::assertTrue(class_exists(ModulesServiceProvider::class));
        self::assertTrue(is_subclass_of(ModulesServiceProvider::class, ServiceProvider::class));
        self::assertSame(ServiceProvider::class, get_parent_class(ModulesServiceProvider::class));
    }

    public function test_manifest_registers_modules_and_invalid_registration_fails_fast(): void
    {
        $providers = [
            IdentityServiceProvider::class,
            KolServiceProvider::class,
            OrdersServiceProvider::class,
            LogisticsServiceProvider::class,
            FinanceServiceProvider::class,
            GuidesServiceProvider::class,
            RuntimeServiceProvider::class,
            SearchServiceProvider::class,
            AuditServiceProvider::class,
            SyncServiceProvider::class,
            ManualSyncServiceProvider::class,
            ShellServiceProvider::class,
            DashboardServiceProvider::class,
            CustomersServiceProvider::class,
            ProductsServiceProvider::class,
            ProcurementServiceProvider::class,
            InventoryServiceProvider::class,
            MarketingServiceProvider::class,
            NotificationCenterServiceProvider::class,
            SupportServiceProvider::class,
            BackupServiceProvider::class,
            BusinessProjectsServiceProvider::class,
            TrafficServiceProvider::class,
        ];
        self::assertSame($providers, ModuleManifest::providers());
        $application = $this->createMock(Application::class);
        $application->expects(self::exactly(count($providers)))
            ->method('register')
            ->with(self::callback(fn (string $provider): bool => in_array($provider, $providers, true)));
        $provider = new ModulesServiceProvider($application);
        $provider->register();
        self::assertSame([], ModuleManifest::validate([]));
    }

    public function test_missing_provider_fails_fast_with_class_name(): void
    {
        $provider = 'App\\Modules\\MissingProvider';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($provider);
        ModuleManifest::validate([$provider]);
    }

    public function test_illegal_provider_type_fails_fast_with_class_name(): void
    {
        $provider = self::class;
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($provider);
        ModuleManifest::validate([$provider]);
    }

    public function test_valid_provider_order_is_preserved(): void
    {
        self::assertSame([
            AppServiceProvider::class,
            ModulesServiceProvider::class,
            IdentityServiceProvider::class,
            OrdersServiceProvider::class,
            LogisticsServiceProvider::class,
            FinanceServiceProvider::class,
            RuntimeServiceProvider::class,
            AuditServiceProvider::class,
            SyncServiceProvider::class,
            ManualSyncServiceProvider::class,
            ShellServiceProvider::class,
            DashboardServiceProvider::class,
            CustomersServiceProvider::class,
            ProductsServiceProvider::class,
            InventoryServiceProvider::class,
            BackupServiceProvider::class,
        ], ModuleManifest::validate([
            AppServiceProvider::class,
            ModulesServiceProvider::class,
            IdentityServiceProvider::class,
            OrdersServiceProvider::class,
            LogisticsServiceProvider::class,
            FinanceServiceProvider::class,
            RuntimeServiceProvider::class,
            AuditServiceProvider::class,
            SyncServiceProvider::class,
            ManualSyncServiceProvider::class,
            ShellServiceProvider::class,
            DashboardServiceProvider::class,
            CustomersServiceProvider::class,
            ProductsServiceProvider::class,
            InventoryServiceProvider::class,
            BackupServiceProvider::class,
        ]));
    }

    public function test_bootstrap_provider_order_and_existing_providers_are_frozen(): void
    {
        self::assertSame([AppServiceProvider::class, ModulesServiceProvider::class], require dirname(__DIR__, 2).'/bootstrap/providers.php');
        self::assertTrue(class_exists(AppServiceProvider::class));
        self::assertTrue(class_exists(IdentityServiceProvider::class));
    }

    public function test_provider_sources_are_empty_of_business_dependencies(): void
    {
        foreach ([ModuleManifest::class, ModulesServiceProvider::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('/(?:filesystem|glob|scandir|Finder|convention|Route::|Migration|View|Event|Queue|Schedule|Redis|\$name)/i', $source, 'PROV-001 source violations');
        }
    }

    public function test_manifest_and_provider_do_not_use_external_service_names(): void
    {
        $contents = file_get_contents((new \ReflectionClass(ModulesServiceProvider::class))->getFileName());
        $manifest = file_get_contents((new \ReflectionClass(ModuleManifest::class))->getFileName());
        self::assertIsString($contents);
        self::assertIsString($manifest);
        self::assertDoesNotMatchRegularExpression('/(?:DB|Redis|Queue|Schedule|Route|Event|Inertia|filesystem|config\s*\()/i', $contents.$manifest);
    }
}

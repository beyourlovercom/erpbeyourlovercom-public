<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function failClosed(string $message): never
{
    fwrite(STDERR, "E2E provisioning safety gate failed: {$message}\n");
    exit(1);
}

function assertBootstrapCacheIsolation(Application $app, string $runRoot): void
{
    $cacheRoot = $runRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
    $cachePaths = [
        $app->getCachedConfigPath() => $cacheRoot.DIRECTORY_SEPARATOR.'config.php',
        $app->getCachedRoutesPath() => $cacheRoot.DIRECTORY_SEPARATOR.'routes-v7.php',
        $app->getCachedEventsPath() => $cacheRoot.DIRECTORY_SEPARATOR.'events.php',
        $app->getCachedServicesPath() => $cacheRoot.DIRECTORY_SEPARATOR.'services.php',
        $app->getCachedPackagesPath() => $cacheRoot.DIRECTORY_SEPARATOR.'packages.php',
    ];

    foreach ($cachePaths as $actual => $expected) {
        if ($actual !== $expected) {
            failClosed('bootstrap cache path');
        }
    }

    foreach ([$app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath()] as $activeCache) {
        if (is_file($activeCache) || is_link($activeCache)) {
            failClosed('active bootstrap cache');
        }
    }
}

function assertEffectiveLaravelRuntime(Application $app, string $database): PDO
{
    if (! $app->environment('testing')) {
        failClosed('effective environment');
    }
    if (config('database.default') !== 'sqlite') {
        failClosed('effective database connection');
    }

    $configuredDatabase = config('database.connections.sqlite.database');
    if (! is_string($configuredDatabase) || realpath($configuredDatabase) !== $database) {
        failClosed('effective database path');
    }
    if (config('erp_local.admin_bypass.enabled') !== false) {
        failClosed('effective local bypass');
    }
    if ($app->configurationIsCached() || $app->routesAreCached() || $app->eventsAreCached()) {
        failClosed('effective bootstrap cache');
    }

    $connection = DB::connection('sqlite');
    if ($connection->getDriverName() !== 'sqlite') {
        failClosed('effective PDO driver');
    }

    $pdo = $connection->getPdo();
    $mainDatabase = null;
    foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $attachedDatabase) {
        if (($attachedDatabase['name'] ?? null) === 'main') {
            $mainDatabase = $attachedDatabase['file'] ?? null;
            break;
        }
    }
    if (! is_string($mainDatabase) || realpath($mainDatabase) !== $database) {
        failClosed('effective PDO database path');
    }

    return $pdo;
}

$repositoryRoot = realpath(dirname(__DIR__, 3));
$requestedRunRoot = (string) getenv('BYL_E2E_RUN_ROOT');
$requestedDatabase = (string) getenv('DB_DATABASE');
$runRoot = realpath($requestedRunRoot);
$database = realpath($requestedDatabase);
$runtimePreflight = ($argv[1] ?? null) === '--runtime-preflight';

if (isset($argv[1]) && ! $runtimePreflight) {
    failClosed('unsupported mode');
}

if (getenv('APP_ENV') !== 'testing') {
    failClosed('APP_ENV');
}
if (getenv('BYL_E2E_DISPOSABLE') !== 'true') {
    failClosed('disposable marker');
}
if (getenv('ERP_LOCAL_ADMIN_BYPASS') !== 'false') {
    failClosed('local bypass');
}
if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_URL') !== false && getenv('DB_URL') !== '') {
    failClosed('connection');
}
if ($repositoryRoot === false || $runRoot === false || $database === false) {
    failClosed('real paths');
}
if (is_link($requestedRunRoot) || is_link($requestedDatabase)) {
    failClosed('symlink');
}
if ($runRoot === $repositoryRoot || str_starts_with($runRoot, $repositoryRoot.DIRECTORY_SEPARATOR)) {
    failClosed('repository-contained root');
}
if (dirname($database) !== $runRoot.DIRECTORY_SEPARATOR.'database') {
    failClosed('database path');
}
if (basename($database) !== 'e2e.sqlite' || ! is_file($database)) {
    failClosed('database file');
}
if ($runtimePreflight && filesize($database) !== 0) {
    failClosed('database is not fresh');
}

$runId = preg_replace('/[^a-z0-9]/i', '', (string) getenv('BYL_E2E_RUN_ID'));
$activePassword = (string) getenv('BYL_E2E_ACTIVE_PASSWORD');
$inactivePassword = (string) getenv('BYL_E2E_INACTIVE_PASSWORD');
if ($runId === '' || strlen($activePassword) < 24 || strlen($inactivePassword) < 24) {
    failClosed('synthetic credentials');
}

require $repositoryRoot.'/vendor/autoload.php';
$app = require $repositoryRoot.'/bootstrap/app.php';
assertBootstrapCacheIsolation($app, $runRoot);
$app->make(Kernel::class)->bootstrap();
$pdo = assertEffectiveLaravelRuntime($app, $database);

if ($runtimePreflight) {
    fwrite(STDOUT, json_encode(['runtime_preflight' => 'pass', 'database' => 'disposable-sqlite'], JSON_THROW_ON_ERROR)."\n");
    exit(0);
}

$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['migrations', 'users', 'roles', 'permissions', 'role_user', 'products'] as $requiredTable) {
    if (! in_array($requiredTable, $tables, true)) {
        failClosed('schema not migrated');
    }
}
if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    failClosed('database is not fresh');
}
unset($pdo);

DB::transaction(function () use ($runId, $activePassword, $inactivePassword): void {
    Product::query()->create([
        'sku' => 'E2E-SKU-0001',
        'name' => 'E2E Synthetic Product',
        'source_type' => 'manual',
        'is_active' => true,
    ]);

    $role = Role::query()->create(['key' => 'super_admin', 'label' => 'E2E Super Admin']);
    $authorized = User::query()->create([
        'name' => 'E2E Authorized',
        'email' => "authorized-{$runId}@byl-e2e.invalid",
        'password' => Hash::make($activePassword),
        'is_active' => true,
    ]);
    $authorized->roles()->attach($role);

    User::query()->create([
        'name' => 'E2E Unauthorized',
        'email' => "unauthorized-{$runId}@byl-e2e.invalid",
        'password' => Hash::make($activePassword),
        'is_active' => true,
    ]);
    // All records below are constrained by the fail-closed disposable SQLite
    // checks at the top of this file; they never touch a real ERP database.
    $specialistRole = Role::query()->create(['key' => 'e2e_kol_specialist', 'label' => 'E2E KOL Specialist']);
    $managerRole = Role::query()->create(['key' => 'e2e_kol_manager', 'label' => 'E2E KOL Manager']);
    foreach (['dashboard.view', 'kol.view', 'orders.view', 'orders.create', 'products.view'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $key]);
        $specialistRole->permissions()->attach($permission);
        $managerRole->permissions()->attach($permission);
    }
    $managerRole->permissions()->attach(
        Permission::query()->firstOrCreate(['key' => 'kol.views.manage'], ['label' => 'kol.views.manage']),
    );
    $roleUsers = [];
    foreach ([
        ['emma', $specialistRole],
        ['other', $specialistRole],
        ['manager', $managerRole],
    ] as [$label, $role]) {
        $user = User::query()->create([
            'name' => 'E2E KOL '.ucfirst($label),
            'email' => "kol-{$label}-{$runId}@byl-e2e.invalid",
            'password' => Hash::make($activePassword),
            'is_active' => true,
        ]);
        $user->roles()->attach($role);
        $roleUsers[$label] = $user;
    }
    foreach (['emma', 'other', 'manager'] as $label) {
        $owner = $roleUsers[$label];
        $order = Order::query()->create([
            'internal_order_number' => 'E2E-KOL-'.strtoupper($label),
            'erp_order_id' => '007erp-1009-k9'.$owner->id,
            'source_type' => 'manual',
            'order_kind' => 'kol_fulfillment',
            'external_order_number' => 'E2E-KOL-'.strtoupper($label),
            'order_date' => '2026-10-09',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
        $order->shipment()->create([
            'shipment_status' => 'shipped',
            'carrier' => 'DHL',
            'tracking_number' => 'E2E-TRACK-'.strtoupper($label).'-123456789012345678901234567890',
        ]);
    }
    $customer = Order::query()->create([
        'internal_order_number' => 'E2E-CUSTOMER-PRIVATE',
        'erp_order_id' => '007erp-1009-c999',
        'source_type' => 'manual',
        'order_kind' => 'customer',
        'external_order_number' => 'E2E-CUSTOMER-PRIVATE',
        'order_date' => '2026-10-09',
        'order_status' => 'pending_shipment',
        'currency' => 'USD',
        'owner_id' => $roleUsers['manager']->id,
    ]);
    $customer->shipment()->create([
        'shipment_status' => 'shipped',
        'tracking_number' => 'E2E-CUSTOMER-PRIVATE-TRACK',
    ]);

    User::query()->create([
        'name' => 'E2E Inactive',
        'email' => "inactive-{$runId}@byl-e2e.invalid",
        'password' => Hash::make($inactivePassword),
        'is_active' => false,
    ]);
});

fwrite(STDOUT, json_encode(['synthetic_users' => 6, 'synthetic_products' => 1, 'synthetic_kol_orders' => 3, 'synthetic_customer_orders' => 1, 'database' => 'disposable-sqlite'], JSON_THROW_ON_ERROR)."\n");

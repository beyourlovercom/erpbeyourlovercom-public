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
use App\Modules\ModuleManifest;
use App\Modules\ModulesServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Products\Providers\ProductsServiceProvider;
use App\Modules\Runtime\Providers\RuntimeServiceProvider;
use App\Modules\Shell\Providers\ShellServiceProvider;
use App\Modules\Support\Providers\SupportServiceProvider;
use App\Modules\Sync\Providers\ManualSyncServiceProvider;
use App\Modules\Sync\Providers\SyncServiceProvider;
use App\Modules\Traffic\Providers\TrafficServiceProvider;
use App\Providers\AppServiceProvider;
use App\Support\AuthorizationKeys;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;

final class ModuleArchitectureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/app/Modules/ModuleManifest.php';
        require_once dirname(__DIR__, 2).'/app/Modules/ModulesServiceProvider.php';
    }

    private const BASELINE = '7e1f0a7dbaa2b686be552c3683680121fa6f09c5';

    private const MODULE_FILES = [
        'app/Modules/Audit/Application/AuditActor.php',
        'app/Modules/Audit/Application/AuditService.php',
        'app/Modules/Audit/Application/AuditSource.php',
        'app/Modules/Audit/Application/AuditWriteException.php',
        'app/Modules/Audit/Application/Contracts/AuditRecorder.php',
        'app/Modules/Audit/Application/Contracts/AuditWriter.php',
        'app/Modules/Audit/Application/CustomerActivityProjection.php',
        'app/Modules/Audit/Application/ProductActivityProjection.php',
        'app/Modules/Audit/Experience/Controllers/AuditRecordController.php',
        'app/Modules/Audit/Experience/Routes/web.php',
        'app/Modules/Audit/Infrastructure/EloquentAuditWriter.php',
        'app/Modules/Audit/Infrastructure/ImmutableAuditBuilder.php',
        'app/Modules/Audit/Infrastructure/Models/AuditRecord.php',
        'app/Modules/Audit/Providers/AuditServiceProvider.php',
        'app/Modules/Backup/Application/BackupArtifactCleanupService.php',
        'app/Modules/Backup/Application/BackupArtifactData.php',
        'app/Modules/Backup/Application/BackupEngineService.php',
        'app/Modules/Backup/Application/BackupRetentionResult.php',
        'app/Modules/Backup/Application/BackupRetentionService.php',
        'app/Modules/Backup/Application/BackupRunData.php',
        'app/Modules/Backup/Application/BackupScheduleCadence.php',
        'app/Modules/Backup/Application/BackupScheduleSettingData.php',
        'app/Modules/Backup/Application/BackupScheduleSettings.php',
        'app/Modules/Backup/Application/BackupState.php',
        'app/Modules/Backup/Application/BackupType.php',
        'app/Modules/Backup/Application/ChecksumService.php',
        'app/Modules/Backup/Application/Contracts/AutomaticBackupPolicyRepository.php',
        'app/Modules/Backup/Application/Contracts/BackupEncryptor.php',
        'app/Modules/Backup/Application/Contracts/BackupRepository.php',
        'app/Modules/Backup/Application/Contracts/DatabaseDumper.php',
        'app/Modules/Backup/Application/Contracts/DatabaseRestorer.php',
        'app/Modules/Backup/Application/Contracts/GithubReleaseDeletionGateway.php',
        'app/Modules/Backup/Application/Contracts/GithubReleaseGateway.php',
        'app/Modules/Backup/Application/Contracts/PreRestoreSnapshotter.php',
        'app/Modules/Backup/Application/Contracts/RestoreCompatibility.php',
        'app/Modules/Backup/Application/Contracts/RestoreEncryptor.php',
        'app/Modules/Backup/Application/Contracts/RestoreHealthChecker.php',
        'app/Modules/Backup/Application/Contracts/PostRestoreSchemaUpdater.php',
        'app/Modules/Backup/Application/Contracts/RestorePackageExtractor.php',
        'app/Modules/Backup/Application/Contracts/RestoreRollbackExecutor.php',
        'app/Modules/Backup/Application/Contracts/RestoreSource.php',
        'app/Modules/Backup/Application/Contracts/StorageArchiver.php',
        'app/Modules/Backup/Application/Contracts/StorageRestorer.php',
        'app/Modules/Backup/Application/DatabaseDumpAdapter.php',
        'app/Modules/Backup/Application/Exceptions/GithubReleasePublishException.php',
        'app/Modules/Backup/Application/GithubReleasePublicationData.php',
        'app/Modules/Backup/Application/GithubReleasePublishData.php',
        'app/Modules/Backup/Application/GithubReleasePublisher.php',
        'app/Modules/Backup/Application/ManifestBuilder.php',
        'app/Modules/Backup/Application/ManifestContract.php',
        'app/Modules/Backup/Application/PackageBuilder.php',
        'app/Modules/Backup/Application/RestoreCompatibilityValidator.php',
        'app/Modules/Backup/Application/RestoreFailure.php',
        'app/Modules/Backup/Application/RestoreMode.php',
        'app/Modules/Backup/Application/RestoreOrchestrator.php',
        'app/Modules/Backup/Application/RestorePackageValidator.php',
        'app/Modules/Backup/Application/RestoreRequest.php',
        'app/Modules/Backup/Application/RestoreRunData.php',
        'app/Modules/Backup/Application/RestoreState.php',
        'app/Modules/Backup/Application/RestoreStateMachine.php',
        'app/Modules/Backup/Application/StorageArchiveAdapter.php',
        'app/Modules/Backup/Application/StreamingAesGcmEncryptor.php',
        'app/Modules/Backup/Experience/Console/Commands/RunAutomaticBackupCommand.php',
        'app/Modules/Backup/Experience/Controllers/BackupController.php',
        'app/Modules/Backup/Experience/Jobs/ApplyBackupRetentionJob.php',
        'app/Modules/Backup/Experience/Jobs/CreateBackupJob.php',
        'app/Modules/Backup/Experience/Jobs/PublishBackupToGithubJob.php',
        'app/Modules/Backup/Experience/Routes/web.php',
        'app/Modules/Backup/Infrastructure/ArtisanPostRestoreSchemaUpdater.php',
        'app/Modules/Backup/Infrastructure/CurrentRestoreCompatibility.php',
        'app/Modules/Backup/Infrastructure/LocalPreRestoreSnapshotter.php',
        'app/Modules/Backup/Infrastructure/MysqlDatabaseRestorer.php',
        'app/Modules/Backup/Infrastructure/RuntimeRestoreHealthChecker.php',
        'app/Modules/Backup/Infrastructure/SnapshotRestoreRollbackExecutor.php',
        'app/Modules/Backup/Infrastructure/EloquentAutomaticBackupPolicyRepository.php',
        'app/Modules/Backup/Infrastructure/EloquentBackupRepository.php',
        'app/Modules/Backup/Infrastructure/HttpGithubReleaseDeletionGateway.php',
        'app/Modules/Backup/Infrastructure/HttpGithubReleaseGateway.php',
        'app/Modules/Backup/Infrastructure/HttpGithubRestoreSource.php',
        'app/Modules/Backup/Infrastructure/LocalRestoreSource.php',
        'app/Modules/Backup/Infrastructure/Models/BackupArtifact.php',
        'app/Modules/Backup/Infrastructure/Models/BackupRun.php',
        'app/Modules/Backup/Infrastructure/Models/BackupScheduleSetting.php',
        'app/Modules/Backup/Infrastructure/ZstdTarRestorePackageExtractor.php',
        'app/Modules/Backup/Infrastructure/ZstdTarStorageRestorer.php',
        'app/Modules/Backup/Providers/BackupServiceProvider.php',
        'app/Modules/BusinessProjects/Application/BusinessProjectService.php',
        'app/Modules/BusinessProjects/Application/Contracts/BusinessProjectRepository.php',
        'app/Modules/BusinessProjects/Infrastructure/Models/BusinessProject.php',
        'app/Modules/BusinessProjects/Infrastructure/Models/BusinessProjectSite.php',
        'app/Modules/BusinessProjects/Infrastructure/Repositories/EloquentBusinessProjectRepository.php',
        'app/Modules/BusinessProjects/Providers/BusinessProjectsServiceProvider.php',
        'app/Modules/Customers/Application/CompanyService.php',
        'app/Modules/Customers/Application/CustomerGroupService.php',
        'app/Modules/Customers/Application/CustomerImportService.php',
        'app/Modules/Customers/Application/CustomerSegmentationQuery.php',
        'app/Modules/Customers/Application/CustomerService.php',
        'app/Modules/Customers/Application/CustomerSubscriberProjection.php',
        'app/Modules/Customers/Domain/CountryCodeNormalizer.php',
        'app/Modules/Customers/Domain/Exceptions/InvalidCompanyAttributeException.php',
        'app/Modules/Customers/Domain/WebsiteDomainNormalizer.php',
        'app/Modules/Customers/Experience/Controllers/CompanyController.php',
        'app/Modules/Customers/Experience/Controllers/CustomerController.php',
        'app/Modules/Customers/Experience/Controllers/CustomerDashboardController.php',
        'app/Modules/Customers/Experience/Routes/web.php',
        'app/Modules/Customers/Infrastructure/Models/Company.php',
        'app/Modules/Customers/Infrastructure/Models/Customer.php',
        'app/Modules/Customers/Infrastructure/Models/CustomerAddress.php',
        'app/Modules/Customers/Infrastructure/Models/CustomerGroup.php',
        'app/Modules/Customers/Infrastructure/Models/CustomerGroupMembership.php',
        'app/Modules/Customers/Infrastructure/Models/CustomerImportBatch.php',
        'app/Modules/Customers/Infrastructure/Models/CustomerImportRow.php',
        'app/Modules/Customers/Providers/CustomersServiceProvider.php',
        'app/Modules/Dashboard/Application/DashboardMetrics.php',
        'app/Modules/Dashboard/Experience/Controllers/DashboardController.php',
        'app/Modules/Dashboard/Experience/Routes/web.php',
        'app/Modules/Dashboard/Providers/DashboardServiceProvider.php',
        'app/Modules/Finance/Application/EcbFxService.php',
        'app/Modules/Finance/Application/CostMaterializationService.php',
        'app/Modules/Finance/Application/DecimalMoney.php',
        'app/Modules/Finance/Application/OrderAnalysisService.php',
        'app/Modules/Finance/Application/ProfitDashboardService.php',
        'app/Modules/Finance/Experience/Controllers/OrderAnalysisController.php',
        'app/Modules/Finance/Experience/Controllers/ProfitDashboardController.php',
        'app/Modules/Finance/Experience/Console/Commands/MaterializeOrderCostsCommand.php',
        'app/Modules/Finance/Experience/Routes/web.php',
        'app/Modules/Finance/Infrastructure/Models/OrderCostEntry.php',
        'app/Modules/Finance/Infrastructure/Models/OrderFinancial.php',
        'app/Modules/Finance/Providers/FinanceServiceProvider.php',
        'app/Modules/Guides/Application/KnowledgeBaseService.php',
        'app/Modules/Guides/Application/MarkdownContentRenderer.php',
        'app/Modules/Guides/Experience/Controllers/GuideController.php',
        'app/Modules/Guides/Experience/Controllers/ReleaseNoteController.php',
        'app/Modules/Guides/Experience/Routes/web.php',
        'app/Modules/Guides/Infrastructure/Models/GuideArticle.php',
        'app/Modules/Guides/Infrastructure/Models/ReleaseNote.php',
        'app/Modules/Guides/Providers/GuidesServiceProvider.php',
        'app/Modules/Identity/Application/AuthorizationKeys.php',
        'app/Modules/Identity/Application/Contracts/AuditRecorder.php',
        'app/Modules/Identity/Application/DataScope.php',
        'app/Modules/Identity/Application/Exceptions/IdentityGuardException.php',
        'app/Modules/Identity/Application/PermissionCatalog.php',
        'app/Modules/Identity/Application/RolePermissionsService.php',
        'app/Modules/Identity/Application/RolePreset.php',
        'app/Modules/Identity/Application/UserDirectory.php',
        'app/Modules/Identity/Application/UserService.php',
        'app/Modules/Identity/Experience/Console/Commands/CreateErpUser.php',
        'app/Modules/Identity/Experience/Controllers/AccountController.php',
        'app/Modules/Identity/Experience/Controllers/AuthController.php',
        'app/Modules/Identity/Experience/Controllers/RolePermissionsController.php',
        'app/Modules/Identity/Experience/Controllers/UserController.php',
        'app/Modules/Identity/Experience/Fortify/AttemptPersistentAuthentication.php',
        'app/Modules/Identity/Experience/Fortify/IdentityFortify.php',
        'app/Modules/Identity/Experience/Middleware/ApplyLocalAdminBypass.php',
        'app/Modules/Identity/Experience/Middleware/EnsureActiveAuthenticatedUser.php',
        'app/Modules/Identity/Experience/Middleware/RequireAnyPermission.php',
        'app/Modules/Identity/Experience/Routes/user-management.php',
        'app/Modules/Identity/Experience/Routes/web.php',
        'app/Modules/Identity/Infrastructure/Models/Permission.php',
        'app/Modules/Identity/Infrastructure/Models/Role.php',
        'app/Modules/Identity/Infrastructure/Models/User.php',
        'app/Modules/Identity/Providers/IdentityServiceProvider.php',
        'app/Modules/Inventory/Application/Contracts/InventoryAvailabilityReader.php',
        'app/Modules/Inventory/Application/Contracts/InventoryBatchSyncRepository.php',
        'app/Modules/Inventory/Application/Contracts/InventoryRepository.php',
        'app/Modules/Inventory/Application/InventoryBatchSyncService.php',
        'app/Modules/Inventory/Application/InventoryService.php',
        'app/Modules/Inventory/Application/Sync/ApiAdapterPlaceholder.php',
        'app/Modules/Inventory/Application/Sync/CsvAdapter.php',
        'app/Modules/Inventory/Application/Sync/NormalizedRow.php',
        'app/Modules/Inventory/Application/Sync/PasteAdapter.php',
        'app/Modules/Inventory/Application/Sync/RowNormalizer.php',
        'app/Modules/Inventory/Application/Sync/XlsxAdapter.php',
        'app/Modules/Inventory/Experience/Controllers/InventoryController.php',
        'app/Modules/Inventory/Experience/Controllers/InventorySyncController.php',
        'app/Modules/Inventory/Experience/Controllers/WarehouseController.php',
        'app/Modules/Inventory/Experience/Routes/web.php',
        'app/Modules/Inventory/Infrastructure/EloquentInventoryAvailabilityReader.php',
        'app/Modules/Inventory/Infrastructure/EloquentInventoryBatchSyncRepository.php',
        'app/Modules/Inventory/Infrastructure/EloquentInventoryRepository.php',
        'app/Modules/Inventory/Infrastructure/Models/InventoryAdjustment.php',
        'app/Modules/Inventory/Infrastructure/Models/InventorySyncBatch.php',
        'app/Modules/Inventory/Infrastructure/Models/InventorySyncRow.php',
        'app/Modules/Inventory/Infrastructure/Models/Warehouse.php',
        'app/Modules/Inventory/Infrastructure/Models/WarehouseInventory.php',
        'app/Modules/Inventory/Infrastructure/Models/WarehouseInventoryLot.php',
        'app/Modules/Inventory/Infrastructure/Models/WarehouseServiceRegion.php',
        'app/Modules/Inventory/Providers/InventoryServiceProvider.php',
        'app/Modules/Kol/Application/AffiliateWp/AffiliateBindingService.php',
        'app/Modules/Kol/Application/AffiliateWp/AffiliateSnapshotSyncService.php',
        'app/Modules/Kol/Application/AffiliateWp/ConnectorResponse.php',
        'app/Modules/Kol/Application/AffiliateWp/CouponProvisioningService.php',
        'app/Modules/Kol/Application/AffiliateWp/CouponSnapshotSyncService.php',
        'app/Modules/Kol/Application/AffiliateWp/KolProvisioningService.php',
        'app/Modules/Kol/Application/AffiliateWp/PayoutSnapshotSyncService.php',
        'app/Modules/Kol/Application/AffiliateWp/PayoutWorkflowService.php',
        'app/Modules/Kol/Application/AffiliateWp/ReferralCommissionSyncService.php',
        'app/Modules/Kol/Application/Approval/CollaborationApprovalService.php',
        'app/Modules/Kol/Application/Approval/KolApprovalDecision.php',
        'app/Modules/Kol/Application/Approval/KolApprovalPolicyEvaluator.php',
        'app/Modules/Kol/Application/Approval/KolApprovalService.php',
        'app/Modules/Kol/Application/Approval/ManagerApprovalCenterService.php',
        'app/Modules/Kol/Application/Contracts/AffiliateWpGateway.php',
        'app/Modules/Kol/Application/Contracts/AffiliateCommissionEvidenceReader.php',
        'app/Modules/Kol/Application/KolOperationalDashboardService.php',
        'app/Modules/Kol/Application/ProductKolProjection.php',
        'app/Modules/Kol/Application/KolScope.php',
        'app/Modules/Kol/Application/KolService.php',
        'app/Modules/Kol/Application/SavedViewService.php',
        'app/Modules/Kol/Experience/Console/Commands/AffiliateWpHealthCommand.php',
        'app/Modules/Kol/Experience/Controllers/AffiliateWpSyncController.php',
        'app/Modules/Kol/Experience/Controllers/KolApprovalCenterController.php',
        'app/Modules/Kol/Experience/Controllers/KolCollaborationController.php',
        'app/Modules/Kol/Experience/Controllers/KolCommunicationController.php',
        'app/Modules/Kol/Experience/Controllers/KolContentController.php',
        'app/Modules/Kol/Experience/Controllers/KolController.php',
        'app/Modules/Kol/Experience/Controllers/KolOpportunityController.php',
        'app/Modules/Kol/Experience/Controllers/KolOverviewController.php',
        'app/Modules/Kol/Experience/Controllers/PayoutWorkflowController.php',
        'app/Modules/Kol/Experience/Controllers/ReferralCommissionController.php',
        'app/Modules/Kol/Experience/Controllers/SavedViewController.php',
        'app/Modules/Kol/Experience/Routes/web.php',
        'app/Modules/Kol/Infrastructure/AffiliateWp/ConnectorException.php',
        'app/Modules/Kol/Infrastructure/AffiliateWp/HmacV1Signer.php',
        'app/Modules/Kol/Infrastructure/AffiliateWp/HttpAffiliateWpGateway.php',
        'app/Modules/Kol/Infrastructure/AffiliateWp/LocalUatAffiliateWpGateway.php',
        'app/Modules/Kol/Infrastructure/Models/Kol.php',
        'app/Modules/Kol/Infrastructure/Models/KolAffiliateAccount.php',
        'app/Modules/Kol/Infrastructure/Models/KolAffiliateCoupon.php',
        'app/Modules/Kol/Infrastructure/Models/KolAffiliatePayout.php',
        'app/Modules/Kol/Infrastructure/Models/KolAffiliateReferral.php',
        'app/Modules/Kol/Infrastructure/Models/KolApprovalDecision.php',
        'app/Modules/Kol/Infrastructure/Models/KolApprovalPolicy.php',
        'app/Modules/Kol/Infrastructure/Models/KolCollaboration.php',
        'app/Modules/Kol/Infrastructure/Models/KolCollaborationApprovalDecision.php',
        'app/Modules/Kol/Infrastructure/Models/KolCollaborationProduct.php',
        'app/Modules/Kol/Infrastructure/Models/KolCommunication.php',
        'app/Modules/Kol/Infrastructure/Models/KolContent.php',
        'app/Modules/Kol/Infrastructure/Models/KolContentAttachment.php',
        'app/Modules/Kol/Infrastructure/Models/KolOpportunity.php',
        'app/Modules/Kol/Infrastructure/Models/KolPayoutRequest.php',
        'app/Modules/Kol/Infrastructure/Models/KolPayoutRequestItem.php',
        'app/Modules/Kol/Infrastructure/Models/KolSalesPerformance.php',
        'app/Modules/Kol/Infrastructure/Models/KolSocialAccount.php',
        'app/Modules/Kol/Infrastructure/Models/KolSocialMetric.php',
        'app/Modules/Kol/Infrastructure/Models/SavedView.php',
        'app/Modules/Kol/Infrastructure/EloquentAffiliateCommissionEvidenceReader.php',
        'app/Modules/Kol/Providers/KolServiceProvider.php',
        'app/Modules/Logistics/Application/CustomerShipmentProjection.php',
        'app/Modules/Logistics/Application/Contracts/CarrierBillingEvidenceReader.php',
        'app/Modules/Logistics/Application/ProductShipmentProjection.php',
        'app/Modules/Logistics/Application/ShipmentService.php',
        'app/Modules/Logistics/Application/WarehouseRecommendationService.php',
        'app/Modules/Logistics/Experience/Controllers/CarrierController.php',
        'app/Modules/Logistics/Experience/Controllers/LogisticsController.php',
        'app/Modules/Logistics/Experience/Routes/web.php',
        'app/Modules/Logistics/Infrastructure/Models/Shipment.php',
        'app/Modules/Logistics/Infrastructure/Models/ShipmentCarrierBilling.php',
        'app/Modules/Logistics/Infrastructure/EloquentCarrierBillingEvidenceReader.php',
        'app/Modules/Logistics/Providers/LogisticsServiceProvider.php',
        'app/Modules/Marketing/Application/Contracts/MarketingSubscriberSyncWriter.php',
        'app/Modules/Marketing/Application/Contracts/WordPressSubscriberGateway.php',
        'app/Modules/Marketing/Application/WordPressSubscriberSyncService.php',
        'app/Modules/Marketing/Experience/Controllers/MarketingController.php',
        'app/Modules/Marketing/Experience/Routes/web.php',
        'app/Modules/Marketing/Infrastructure/AndyCore/HttpWordPressSubscriberGateway.php',
        'app/Modules/Marketing/Infrastructure/EloquentMarketingSubscriberSyncWriter.php',
        'app/Modules/Marketing/Infrastructure/Models/MarketingSubscriber.php',
        'app/Modules/Marketing/Infrastructure/Models/MarketingSubscription.php',
        'app/Modules/Marketing/Providers/MarketingServiceProvider.php',
        'app/Modules/ModuleManifest.php',
        'app/Modules/ModulesServiceProvider.php',
        'app/Modules/Orders/Application/Contracts/WooCommerceOrderGateway.php',
        'app/Modules/Orders/Application/CustomerOrderProjection.php',
        'app/Modules/Orders/Application/ProductOrderProjection.php',
        'app/Modules/Orders/Application/Inbound/NormalizedInboundOrder.php',
        'app/Modules/Orders/Application/Inbound/WooCommerceInboundService.php',
        'app/Modules/Orders/Application/Inbound/WooCommerceOrderNormalizer.php',
        'app/Modules/Orders/Application/OrderIdentityService.php',
        'app/Modules/Orders/Application/OrderKind.php',
        'app/Modules/Orders/Application/OrderKindBackfill.php',
        'app/Modules/Orders/Application/OrderService.php',
        'app/Modules/Orders/Application/OrderSourceType.php',
        'app/Modules/Orders/Application/OrderStatus.php',
        'app/Modules/Orders/Experience/Console/SyncWooCommerceOrdersCommand.php',
        'app/Modules/Orders/Experience/Controllers/OrderController.php',
        'app/Modules/Orders/Experience/Controllers/OrderSavedViewController.php',
        'app/Modules/Orders/Experience/Controllers/WooCommerceInboundController.php',
        'app/Modules/Orders/Experience/Routes/web.php',
        'app/Modules/Orders/Infrastructure/Models/Order.php',
        'app/Modules/Orders/Infrastructure/Models/OrderAddress.php',
        'app/Modules/Orders/Infrastructure/Models/OrderExternalReference.php',
        'app/Modules/Orders/Infrastructure/Models/OrderIdentitySetting.php',
        'app/Modules/Orders/Infrastructure/Models/OrderItem.php',
        'app/Modules/Orders/Infrastructure/Models/OrderOperationalMarker.php',
        'app/Modules/Orders/Infrastructure/WooCommerce/HttpWooCommerceOrderGateway.php',
        'app/Modules/Orders/Providers/OrdersServiceProvider.php',
        'app/Modules/Products/Application/Contracts/ProductExternalReferenceRepository.php',
        'app/Modules/Products/Application/Contracts/ProductCostEvidenceReader.php',
        'app/Modules/Products/Application/Contracts/ProductRepository.php',
        'app/Modules/Products/Application/Contracts/SkuAliasRepository.php',
        'app/Modules/Products/Application/Contracts/SkuReconciliationRepository.php',
        'app/Modules/Products/Application/Contracts/WeightEvidenceRepository.php',
        'app/Modules/Products/Application/Contracts/WooCommerceProductGateway.php',
        'app/Modules/Products/Application/Contracts/WooCommerceProductInboundStore.php',
        'app/Modules/Products/Application/ProductImportResult.php',
        'app/Modules/Products/Application/ProductService.php',
        'app/Modules/Products/Application/ProductWeightEvidenceService.php',
        'app/Modules/Products/Application/SkuAliasService.php',
        'app/Modules/Products/Application/SkuIdentityResolver.php',
        'app/Modules/Products/Application/SkuMatchKey.php',
        'app/Modules/Products/Application/SkuReconciliationService.php',
        'app/Modules/Products/Application/SkuResolveResult.php',
        'app/Modules/Products/Application/WooCommerceProductInboundService.php',
        'app/Modules/Products/Experience/Console/ImportWooCsvCommand.php',
        'app/Modules/Products/Experience/Console/SyncWooCommerceProductsCommand.php',
        'app/Modules/Products/Experience/Controllers/ProductController.php',
        'app/Modules/Products/Experience/Controllers/ProductSkuAliasController.php',
        'app/Modules/Products/Experience/Controllers/ProductSkuController.php',
        'app/Modules/Products/Experience/Controllers/ProductWeightEvidenceController.php',
        'app/Modules/Products/Experience/Routes/web.php',
        'app/Modules/Products/Infrastructure/EloquentProductExternalReferenceRepository.php',
        'app/Modules/Products/Infrastructure/EloquentProductCostEvidenceReader.php',
        'app/Modules/Products/Infrastructure/EloquentProductRepository.php',
        'app/Modules/Products/Infrastructure/EloquentSkuAliasRepository.php',
        'app/Modules/Products/Infrastructure/EloquentSkuReconciliationRepository.php',
        'app/Modules/Products/Infrastructure/EloquentWeightEvidenceRepository.php',
        'app/Modules/Products/Infrastructure/EloquentWooCommerceProductInboundStore.php',
        'app/Modules/Products/Infrastructure/Models/Product.php',
        'app/Modules/Products/Infrastructure/Models/ProductExternalReference.php',
        'app/Modules/Products/Infrastructure/Models/ProductMedia.php',
        'app/Modules/Products/Infrastructure/Models/ProductProviderSnapshot.php',
        'app/Modules/Products/Infrastructure/Models/ProductSkuAlias.php',
        'app/Modules/Products/Infrastructure/Models/ProductSkuReconciliation.php',
        'app/Modules/Products/Infrastructure/Models/ProductWeightEvidence.php',
        'app/Modules/Products/Infrastructure/WooCommerce/HttpWooCommerceProductGateway.php',
        'app/Modules/Products/Providers/ProductsServiceProvider.php',
        'app/Modules/Runtime/Application/Contracts/RedisRuntimeProbe.php',
        'app/Modules/Runtime/Application/QueueJobConvention.php',
        'app/Modules/Runtime/Application/QueueName.php',
        'app/Modules/Runtime/Application/RedisClientCapability.php',
        'app/Modules/Runtime/Application/RuntimeHealthService.php',
        'app/Modules/Runtime/Experience/Console/Commands/RuntimeHealthCommand.php',
        'app/Modules/Runtime/Experience/Controllers/RuntimeHealthController.php',
        'app/Modules/Runtime/Experience/Jobs/RuntimeValidationJob.php',
        'app/Modules/Runtime/Experience/Routes/web.php',
        'app/Modules/Runtime/Infrastructure/RedisRuntimeProbe.php',
        'app/Modules/Runtime/Providers/RuntimeServiceProvider.php',
        'app/Modules/Shell/Application/ErpShellNavigation.php',
        'app/Modules/Shell/Application/LegacyErpNavigationPaths.php',
        'app/Modules/Shell/Application/UserUiPreferenceService.php',
        'app/Modules/Shell/Experience/Controllers/ErpShellController.php',
        'app/Modules/Shell/Experience/Controllers/ShellPlaceholderController.php',
        'app/Modules/Shell/Experience/Controllers/UserUiPreferenceController.php',
        'app/Modules/Shell/Experience/Routes/web.php',
        'app/Modules/Shell/Infrastructure/Models/UserUiPreference.php',
        'app/Modules/Shell/Providers/ShellServiceProvider.php',
        'app/Modules/Support/Application/CustomerSupportProjection.php',
        'app/Modules/Support/Application/SupportTicketService.php',
        'app/Modules/Support/Experience/Controllers/CustomerSupportTicketController.php',
        'app/Modules/Support/Experience/Routes/web.php',
        'app/Modules/Support/Infrastructure/Models/SupportTicket.php',
        'app/Modules/Support/Providers/SupportServiceProvider.php',
        'app/Modules/Traffic/Application/Contracts/GoogleAdsReadClient.php',
        'app/Modules/Traffic/Application/Contracts/TrafficRepository.php',
        'app/Modules/Traffic/Application/Exceptions/GoogleAdsReadException.php',
        'app/Modules/Traffic/Application/GoogleAdsTrafficSyncService.php',
        'app/Modules/Traffic/Application/TrafficService.php',
        'app/Modules/Traffic/Experience/Console/GoogleAdsTrafficSyncCommand.php',
        'app/Modules/Traffic/Infrastructure/GoogleAds/HttpGoogleAdsReadClient.php',
        'app/Modules/Traffic/Infrastructure/Models/LandingPage.php',
        'app/Modules/Traffic/Infrastructure/Models/TrafficCampaign.php',
        'app/Modules/Traffic/Infrastructure/Models/TrafficDailyMetric.php',
        'app/Modules/Traffic/Infrastructure/Models/TrafficInquiryDailyMetric.php',
        'app/Modules/Traffic/Infrastructure/Models/TrafficSourceAccount.php',
        'app/Modules/Traffic/Infrastructure/Repositories/EloquentTrafficRepository.php',
        'app/Modules/Traffic/Providers/TrafficServiceProvider.php',
        'app/Modules/Sync/Application/Contracts/SyncOrchestrationRepository.php',
        'app/Modules/Sync/Application/Contracts/SyncOutboxRepository.php',
        'app/Modules/Sync/Application/Contracts/SyncRunRepository.php',
        'app/Modules/Sync/Application/Contracts/SyncRunTracker.php',
        'app/Modules/Sync/Application/Contracts/WooCommerceTrackingGateway.php',
        'app/Modules/Sync/Application/ManualSyncService.php',
        'app/Modules/Sync/Application/ManualSyncStatus.php',
        'app/Modules/Sync/Application/SyncOrchestrationService.php',
        'app/Modules/Sync/Application/SyncOutboxService.php',
        'app/Modules/Sync/Application/SyncOutboxStatus.php',
        'app/Modules/Sync/Application/SyncOutboxWriteException.php',
        'app/Modules/Sync/Application/SyncRunItemStatus.php',
        'app/Modules/Sync/Application/SyncRunService.php',
        'app/Modules/Sync/Application/SyncRunStatus.php',
        'app/Modules/Sync/Application/WooCommerceTrackingConflictException.php',
        'app/Modules/Sync/Application/WooCommerceTrackingOutboundService.php',
        'app/Modules/Sync/Experience/Controllers/ManualSyncController.php',
        'app/Modules/Sync/Experience/Controllers/SyncOrchestrationController.php',
        'app/Modules/Sync/Experience/Controllers/SyncWorkspaceController.php',
        'app/Modules/Sync/Experience/Controllers/WooCommerceTrackingOutboundController.php',
        'app/Modules/Sync/Experience/Routes/web.php',
        'app/Modules/Sync/Infrastructure/EloquentSyncOrchestrationRepository.php',
        'app/Modules/Sync/Infrastructure/EloquentSyncOutboxRepository.php',
        'app/Modules/Sync/Infrastructure/EloquentSyncRunRepository.php',
        'app/Modules/Sync/Infrastructure/Models/ManualSync.php',
        'app/Modules/Sync/Infrastructure/Models/SyncOutbox.php',
        'app/Modules/Sync/Infrastructure/Models/SyncRun.php',
        'app/Modules/Sync/Infrastructure/Models/SyncRunItem.php',
        'app/Modules/Sync/Infrastructure/WooCommerce/HttpWooCommerceTrackingGateway.php',
        'app/Modules/Sync/Providers/ManualSyncServiceProvider.php',
        'app/Modules/Sync/Providers/SyncServiceProvider.php',
    ];

    private const MODULE_DIRECTORIES = [
        'app/Modules/Audit',
        'app/Modules/Audit/Application',
        'app/Modules/Audit/Application/Contracts',
        'app/Modules/Audit/Experience',
        'app/Modules/Audit/Experience/Controllers',
        'app/Modules/Audit/Experience/Routes',
        'app/Modules/Audit/Infrastructure',
        'app/Modules/Audit/Infrastructure/Models',
        'app/Modules/Audit/Providers',
        'app/Modules/Backup',
        'app/Modules/Backup/Application',
        'app/Modules/Backup/Application/Contracts',
        'app/Modules/Backup/Application/Exceptions',
        'app/Modules/Backup/Experience',
        'app/Modules/Backup/Experience/Console',
        'app/Modules/Backup/Experience/Console/Commands',
        'app/Modules/Backup/Experience/Controllers',
        'app/Modules/Backup/Experience/Jobs',
        'app/Modules/Backup/Experience/Routes',
        'app/Modules/Backup/Infrastructure',
        'app/Modules/Backup/Infrastructure/Models',
        'app/Modules/Backup/Providers',
        'app/Modules/BusinessProjects',
        'app/Modules/BusinessProjects/Application',
        'app/Modules/BusinessProjects/Application/Contracts',
        'app/Modules/BusinessProjects/Infrastructure',
        'app/Modules/BusinessProjects/Infrastructure/Models',
        'app/Modules/BusinessProjects/Infrastructure/Repositories',
        'app/Modules/BusinessProjects/Providers',
        'app/Modules/Customers',
        'app/Modules/Customers/Application',
        'app/Modules/Customers/Domain',
        'app/Modules/Customers/Domain/Exceptions',
        'app/Modules/Customers/Experience',
        'app/Modules/Customers/Experience/Controllers',
        'app/Modules/Customers/Experience/Routes',
        'app/Modules/Customers/Infrastructure',
        'app/Modules/Customers/Infrastructure/Models',
        'app/Modules/Customers/Providers',
        'app/Modules/Dashboard',
        'app/Modules/Dashboard/Application',
        'app/Modules/Dashboard/Experience',
        'app/Modules/Dashboard/Experience/Controllers',
        'app/Modules/Dashboard/Experience/Routes',
        'app/Modules/Dashboard/Providers',
        'app/Modules/Finance',
        'app/Modules/Finance/Application',
        'app/Modules/Finance/Experience',
        'app/Modules/Finance/Experience/Console',
        'app/Modules/Finance/Experience/Console/Commands',
        'app/Modules/Finance/Experience/Controllers',
        'app/Modules/Finance/Experience/Routes',
        'app/Modules/Finance/Infrastructure',
        'app/Modules/Finance/Infrastructure/Models',
        'app/Modules/Finance/Providers',
        'app/Modules/Guides',
        'app/Modules/Guides/Application',
        'app/Modules/Guides/Experience',
        'app/Modules/Guides/Experience/Controllers',
        'app/Modules/Guides/Experience/Routes',
        'app/Modules/Guides/Infrastructure',
        'app/Modules/Guides/Infrastructure/Models',
        'app/Modules/Guides/Providers',
        'app/Modules/Identity',
        'app/Modules/Identity/Application',
        'app/Modules/Identity/Application/Contracts',
        'app/Modules/Identity/Application/Exceptions',
        'app/Modules/Identity/Experience',
        'app/Modules/Identity/Experience/Console',
        'app/Modules/Identity/Experience/Console/Commands',
        'app/Modules/Identity/Experience/Controllers',
        'app/Modules/Identity/Experience/Fortify',
        'app/Modules/Identity/Experience/Middleware',
        'app/Modules/Identity/Experience/Routes',
        'app/Modules/Identity/Infrastructure',
        'app/Modules/Identity/Infrastructure/Models',
        'app/Modules/Identity/Providers',
        'app/Modules/Inventory',
        'app/Modules/Inventory/Application',
        'app/Modules/Inventory/Application/Contracts',
        'app/Modules/Inventory/Application/Sync',
        'app/Modules/Inventory/Experience',
        'app/Modules/Inventory/Experience/Controllers',
        'app/Modules/Inventory/Experience/Routes',
        'app/Modules/Inventory/Infrastructure',
        'app/Modules/Inventory/Infrastructure/Models',
        'app/Modules/Inventory/Providers',
        'app/Modules/Kol',
        'app/Modules/Kol/Application',
        'app/Modules/Kol/Application/AffiliateWp',
        'app/Modules/Kol/Application/Approval',
        'app/Modules/Kol/Application/Contracts',
        'app/Modules/Kol/Experience',
        'app/Modules/Kol/Experience/Console',
        'app/Modules/Kol/Experience/Console/Commands',
        'app/Modules/Kol/Experience/Controllers',
        'app/Modules/Kol/Experience/Routes',
        'app/Modules/Kol/Infrastructure',
        'app/Modules/Kol/Infrastructure/AffiliateWp',
        'app/Modules/Kol/Infrastructure/Models',
        'app/Modules/Kol/Providers',
        'app/Modules/Logistics',
        'app/Modules/Logistics/Application',
        'app/Modules/Logistics/Application/Contracts',
        'app/Modules/Logistics/Experience',
        'app/Modules/Logistics/Experience/Controllers',
        'app/Modules/Logistics/Experience/Routes',
        'app/Modules/Logistics/Infrastructure',
        'app/Modules/Logistics/Infrastructure/Models',
        'app/Modules/Logistics/Providers',
        'app/Modules/Marketing',
        'app/Modules/Marketing/Application',
        'app/Modules/Marketing/Application/Contracts',
        'app/Modules/Marketing/Experience',
        'app/Modules/Marketing/Experience/Controllers',
        'app/Modules/Marketing/Experience/Routes',
        'app/Modules/Marketing/Infrastructure',
        'app/Modules/Marketing/Infrastructure/AndyCore',
        'app/Modules/Marketing/Infrastructure/Models',
        'app/Modules/Marketing/Providers',
        'app/Modules/Orders',
        'app/Modules/Orders/Application',
        'app/Modules/Orders/Application/Contracts',
        'app/Modules/Orders/Application/Inbound',
        'app/Modules/Orders/Experience',
        'app/Modules/Orders/Experience/Console',
        'app/Modules/Orders/Experience/Controllers',
        'app/Modules/Orders/Experience/Routes',
        'app/Modules/Orders/Infrastructure',
        'app/Modules/Orders/Infrastructure/Models',
        'app/Modules/Orders/Infrastructure/WooCommerce',
        'app/Modules/Orders/Providers',
        'app/Modules/Products',
        'app/Modules/Products/Application',
        'app/Modules/Products/Application/Contracts',
        'app/Modules/Products/Experience',
        'app/Modules/Products/Experience/Console',
        'app/Modules/Products/Experience/Controllers',
        'app/Modules/Products/Experience/Routes',
        'app/Modules/Products/Infrastructure',
        'app/Modules/Products/Infrastructure/Models',
        'app/Modules/Products/Infrastructure/WooCommerce',
        'app/Modules/Products/Providers',
        'app/Modules/Runtime',
        'app/Modules/Runtime/Application',
        'app/Modules/Runtime/Application/Contracts',
        'app/Modules/Runtime/Experience',
        'app/Modules/Runtime/Experience/Console',
        'app/Modules/Runtime/Experience/Console/Commands',
        'app/Modules/Runtime/Experience/Controllers',
        'app/Modules/Runtime/Experience/Jobs',
        'app/Modules/Runtime/Experience/Routes',
        'app/Modules/Runtime/Infrastructure',
        'app/Modules/Runtime/Providers',
        'app/Modules/Shell',
        'app/Modules/Shell/Application',
        'app/Modules/Shell/Experience',
        'app/Modules/Shell/Experience/Controllers',
        'app/Modules/Shell/Experience/Routes',
        'app/Modules/Shell/Infrastructure',
        'app/Modules/Shell/Infrastructure/Models',
        'app/Modules/Shell/Providers',
        'app/Modules/Sync',
        'app/Modules/Sync/Application',
        'app/Modules/Sync/Application/Contracts',
        'app/Modules/Sync/Experience',
        'app/Modules/Sync/Experience/Controllers',
        'app/Modules/Sync/Experience/Routes',
        'app/Modules/Sync/Infrastructure',
        'app/Modules/Sync/Infrastructure/Models',
        'app/Modules/Sync/Infrastructure/WooCommerce',
        'app/Modules/Sync/Providers',
        'app/Modules/Support',
        'app/Modules/Support/Application',
        'app/Modules/Support/Experience',
        'app/Modules/Support/Experience/Controllers',
        'app/Modules/Support/Experience/Routes',
        'app/Modules/Support/Infrastructure',
        'app/Modules/Support/Infrastructure/Models',
        'app/Modules/Support/Providers',
        'app/Modules/Traffic',
        'app/Modules/Traffic/Application',
        'app/Modules/Traffic/Application/Contracts',
        'app/Modules/Traffic/Application/Exceptions',
        'app/Modules/Traffic/Experience',
        'app/Modules/Traffic/Experience/Console',
        'app/Modules/Traffic/Infrastructure',
        'app/Modules/Traffic/Infrastructure/GoogleAds',
        'app/Modules/Traffic/Infrastructure/Models',
        'app/Modules/Traffic/Infrastructure/Repositories',
        'app/Modules/Traffic/Providers',
    ];

    private const ROUTE_NAMES = [
        'account.password',
        'account.password.update',
        'account.profile',
        'account.profile.update',
        'accounting.actual-2025',
        'accounting.cost-calculation',
        'accounting.execution-2026',
        'accounting.forecast-2027',
        'accounting.kol-commissions',
        'accounting.monthly',
        'accounting.optimization',
        'accounting.order-costs',
        'accounting.variance',
        'analysis.export',
        'analysis.update',
        'architecture.foundation',
        'backup.automatic.update',
        'backup.index',
        'backup.legacy',
        'customers.companies',
        'customers.companies.archive',
        'customers.companies.create',
        'customers.companies.edit',
        'customers.companies.restore',
        'customers.companies.show',
        'customers.companies.store',
        'customers.companies.update',
        'customers.groups',
        'customers.groups.customers.search',
        'customers.groups.destroy',
        'customers.groups.members.destroy',
        'customers.groups.members.store',
        'customers.groups.store',
        'customers.groups.update',
        'customers.import',
        'customers.import.confirm',
        'customers.import.preview',
        'customers.import.template',
        'customers.library',
        'customers.library.archive',
        'customers.library.create',
        'customers.library.edit',
        'customers.library.export',
        'customers.library.import',
        'customers.library.restore',
        'customers.library.show',
        'customers.library.store',
        'customers.library.update',
        'customers.orders',
        'customers.orders.create',
        'customers.orders.show',
        'customers.support-tickets.create',
        'customers.support-tickets.edit',
        'customers.support-tickets.show',
        'customers.support-tickets.store',
        'customers.support-tickets.update',
        'dashboard',
        'guides.create',
        'guides.edit',
        'guides.manuals',
        'guides.publish',
        'guides.releases.index',
        'guides.show',
        'guides.store',
        'guides.unpublish',
        'guides.update',
        'guides.videos',
        'inventory.add',
        'inventory.list',
        'inventory.product-search',
        'inventory.products',
        'inventory.stock.store',
        'inventory.stock.update',
        'inventory.store',
        'inventory.sync',
        'inventory.sync.confirm',
        'inventory.sync.history',
        'inventory.sync.preview',
        'inventory.sync.show',
        'inventory.warehouse.billing',
        'inventory.warehouse.regions',
        'inventory.warehouse.show',
        'inventory.warehouse.update',
        'inventory.warehouses',
        'kol.affiliate',
        'kol.affiliatewp',
        'kol.affiliatewp.bind',
        'kol.affiliatewp.convert',
        'kol.affiliatewp.coupons.provision',
        'kol.affiliatewp.coupons.sync',
        'kol.affiliatewp.provision',
        'kol.affiliatewp.sync',
        'kol.approvals',
        'kol.approvals.collaborations',
        'kol.approvals.kols',
        'kol.approvals.payouts',
        'kol.approvals.collaborations.approve',
        'kol.approvals.collaborations.reject',
        'kol.approvals.kols.approve',
        'kol.approvals.kols.reject',
        'kol.approvals.payouts.approve',
        'kol.approvals.payouts.reject',
        'kol.collaborations',
        'kol.commissions',
        'kol.commissions.sync',
        'kol.communications',
        'kol.content',
        'kol.content.options',
        'kol.contents.evidence.destroy',
        'kol.contents.evidence.show',
        'kol.contents.evidence.store',
        'kol.contents.store',
        'kol.contents.sync-post-image',
        'kol.contents.update',
        'kol.library',
        'kol.library.archive',
        'kol.library.avatar',
        'kol.library.collaborations.fulfillment-order',
        'kol.library.collaborations.store',
        'kol.library.collaborations.update',
        'kol.library.communications.store',
        'kol.library.communications.update',
        'kol.library.create',
        'kol.library.edit',
        'kol.library.opportunities.convert-collaboration',
        'kol.library.opportunities.store',
        'kol.library.opportunities.update',
        'kol.library.reactivate',
        'kol.library.show',
        'kol.library.store',
        'kol.library.update',
        'kol.opportunities',
        'kol.orders',
        'kol.orders.create',
        'kol.orders.mine',
        'kol.orders.show',
        'kol.orders.team',
        'kol.overview',
        'kol.payouts',
        'kol.payouts.approve',
        'kol.payouts.complete',
        'kol.payouts.reject',
        'kol.payouts.store',
        'kol.payouts.sync',
        'kol.samples',
        'kol.views.destroy',
        'kol.views.store',
        'kol.views.update',
        'login',
        'login.store',
        'logistics.awaiting-tracking',
        'logistics.carriers',
        'logistics.carriers.show',
        'logistics.draft',
        'logistics.exceptions',
        'logistics.in-transit',
        'logistics.payload',
        'logistics.pending',
        'logistics.reopen',
        'logistics.ship',
        'logout',
        'marketing.automations',
        'marketing.campaigns',
        'marketing.newsletters',
        'marketing.promotions',
        'marketing.subscribers',
        'orders.export',
        'orders.export.all',
        'orders.export.procurement',
        'orders.index',
        'orders.operational-markers.update',
        'orders.procurement',
        'orders.store',
        'orders.store.customers',
        'orders.update',
        'orders.update.customers',
        'orders.views.destroy',
        'orders.views.store',
        'orders.views.update',
        'password.confirm.store',
        'password.confirmation',
        'procurement.files',
        'procurement.records',
        'products.create',
        'products.dashboard',
        'products.destroy',
        'products.edit',
        'products.import',
        'products.import.store',
        'products.import.template',
        'products.list',
        'products.reconciliations.ignore',
        'products.reconciliations.reopen',
        'products.reconciliations.resolve',
        'products.sku-aliases',
        'products.sku-aliases.destroy',
        'products.sku-aliases.store',
        'products.sku-search',
        'products.skus',
        'products.show',
        'products.store',
        'products.update',
        'products.weight-evidence.adopt',
        'products.weight-evidence.import.confirm',
        'products.weight-evidence.import.preview',
        'products.weight-evidence.index',
        'products.weight-evidence.reject',
        'products.weight-evidence.store',
        'products.weight.clear',
        'root',
        'settings.audit-logs',
        'settings.changelog',
        'settings.order-identity.update',
        'settings.roles-permissions',
        'settings.roles-permissions.update',
        'settings.statuses',
        'settings.warehouses',
        'accounting.index',
        'shell.customers',
        'shell.guides',
        'shell.inventory',
        'shell.kol',
        'shell.logistics',
        'shell.marketing',
        'shell.procurement',
        'shell.settings',
        'shell.sync',
        'storage.local',
        'storage.local.upload',
        'sync.complete',
        'sync.customers.import',
        'sync.exports',
        'sync.kol',
        'sync.orchestration.reconcile',
        'sync.orchestration.recover-stale',
        'sync.orchestration.retry',
        'sync.orchestration.tracking-all-pending',
        'sync.orchestration.tracking-selected',
        'sync.orders',
        'sync.payload',
        'sync.reopen',
        'sync.return',
        'sync.runs.show',
        'sync.tracking-outbound',
        'sync.warehouses',
        'sync.wpapi',
        'sync.wpapi.connection',
        'sync.wpapi.health',
        'sync.wpapi.subscribers',
        'system.audit-records.index',
        'system.runtime.health',
        'ui.preferences.show',
        'ui.preferences.update',
        'user.create',
        'user.destroy',
        'user.disable',
        'user.edit',
        'user.enable',
        'user.index',
        'user.password',
        'user.permissions',
        'user.role',
        'user.store',
        'user.update',
        'woocommerce.inbound.historical',
        'woocommerce.inbound.incremental',
        'woocommerce.inbound.index',
        'woocommerce.inbound.probe',
        'woocommerce.inbound.single',
    ];

    private const PERMISSION_KEYS = [
        'accounting.view',
        'analysis.view',
        'architecture.foundation.view',
        'audit.view',
        'backup.create',
        'backup.delete',
        'backup.download',
        'backup.manage',
        'backup.publish_github',
        'backup.restore',
        'backup.view',
        'customers.export',
        'customers.import',
        'customers.operate',
        'customers.view',
        'dashboard.view',
        'guides.manage',
        'guides.view',
        'inventory.operate',
        'inventory.view',
        'inventory.warehouses.manage',
        'kol.affiliates.manage',
        'kol.approvals.manage',
        'kol.operate',
        'kol.payouts.approve',
        'kol.payouts.manage',
        'kol.payouts.request',
        'kol.view',
        'kol.views.manage',
        'logistics.operate',
        'logistics.view',
        'marketing.operate',
        'marketing.view',
        'orders.create',
        'orders.edit',
        'orders.view',
        'procurement.view',
        'products.operate',
        'products.view',
        'prototype.view',
        'sync.operate',
        'sync.view',
        'system.permissions.manage',
    ];

    private const SUPPORT_FILES = [
        'app/Support/Audit/AuditActor.php', 'app/Support/Audit/AuditService.php',
        'app/Support/Audit/AuditSource.php', 'app/Support/Audit/AuditWriteException.php',
        'app/Support/Audit/AuditWriter.php', 'app/Support/Audit/EloquentAuditWriter.php',
        'app/Support/Audit/ImmutableAuditBuilder.php', 'app/Support/AuthorizationKeys.php',
        'app/Support/DataScope.php', 'app/Support/ErpShellNavigation.php',
        'app/Support/LegacyErpNavigationPaths.php', 'app/Support/Runtime/QueueJobConvention.php',
        'app/Support/Runtime/QueueName.php', 'app/Support/Runtime/RedisClientCapability.php',
        'app/Support/Runtime/RedisRuntimeProbe.php', 'app/Support/Runtime/RuntimeHealthService.php',
        'app/Support/Sync/SyncOutboxService.php', 'app/Support/Sync/SyncOutboxStatus.php',
        'app/Support/Sync/SyncOutboxWriteException.php',
    ];

    private const MIGRATIONS = [
        'database/migrations/0001_01_01_000000_create_users_table.php' => '10d79883bc08ce46510f9ad8b1830b4d06d586dc56ae2c8bac3b3e491f69a90f',
        'database/migrations/0001_01_01_000001_create_cache_table.php' => 'fbb4665e5a977e71df4e74ec75b3c06ca4f17ad0de7138fe9786639189978e77',
        'database/migrations/0001_01_01_000002_create_jobs_table.php' => 'df4910687180313a14a3f96a8bddd808ae145ed1b107cd772caf865c0dc2b746',
        'database/migrations/2026_07_31_040000_create_roles_permissions_tables.php' => 'f7ec168bc3d73ddec6d320c18baf14abfd220bde52e717cc67eecc12aa7bc3c5',
        'database/migrations/2026_07_31_040001_add_is_active_to_users_table.php' => 'abcf4fdf4ba1f3e799acf915e3dc455f8365ce595bd254ee484eec249c0b40fd',
        'database/migrations/2026_07_31_040002_create_audit_records_table.php' => '9646245c3f3be220a760546d3fdf54f9656118b644104bd8e9177122e304fcb0',
        'database/migrations/2026_08_01_000000_create_sync_outbox_table.php' => '1404b68d7e91f3c0c7762293dae0442c1da3a9e4ae889294cf00d5cbf61b3ee1',
        'database/migrations/2026_08_05_000000_create_orders_tables.php' => 'c569493a9c671a44d8ff2e5d57624e4f4a8f3aba07b0b05baa255cfd0d855319',
        'database/migrations/2026_08_05_000001_create_shipments_table.php' => 'b77bd4fe75277ac2173689aa1fcfc8f81d91b13e7c9e53a1a5d49c51569c010d',
        'database/migrations/2026_08_05_000002_create_manual_syncs_table.php' => 'a71467bb42ab9c0a2edcbc9519619138c4bd8e5b0e15b5246e6f9a6a44fa14d5',
        'database/migrations/2026_08_05_000003_create_order_costs_table.php' => '107416001d2e658fad74870e656b2d23f45d21dbe2a892d512af1d043bd815cb',
        'database/migrations/2026_08_23_000000_create_companies_table.php' => '860faaca4a5c8f2654164798fc4d9717df2f291642f4c5754a1fda22ac652401',
        'database/migrations/2026_08_24_000000_add_order_kind_to_orders_table.php' => '7775e65ca4ec9e4d2de5fe6b603bf8118b35d2f999f95f8e85471ef280dd89c8',
        'database/migrations/2026_08_24_000000_add_permission_mode_and_permission_user_pivot.php' => '8f84c0b97530d31395cfbc7968476b1734498019bf47830e7974ed7d59af3be6',
        'database/migrations/2026_08_24_000001_make_order_address_snapshot_fields_nullable.php' => '0c3df13a4def87cb761dbac6c2a15b58fdf3c1352431456f39992b85f9ceaaf9',
        'database/migrations/2026_08_24_100000_create_products_table.php' => 'a7ddaa6125c296d3b67fd85eb8c3728764630207a121d57046702defb3bda1c0',
        'database/migrations/2026_08_25_000100_create_inventory_foundation_tables.php' => '5c04ba76e1e90e51970b492aca4f386ed02270eb4f201493d1d90ccd74794e07',
        'database/migrations/2026_08_25_000101_seed_initial_warehouses.php' => '47ac9451febaa5b34ddc0ab6e76e2a208264227551caf4818dd0624afc0f5fa9',
        'database/migrations/2026_08_25_000200_create_inventory_batch_sync_tables.php' => '2d4048d7ccc8ddbe916fdf6b2cf5158ba30585ddc6083ff0e106359e2e004098',
        'database/migrations/2026_08_27_000000_repair_inventory_sync_preview_metrics.php' => 'd791cc4fc3aebecafcfff2c0fc8f5b02b51f142b3dc9986ee7f8fdcd17b7aded',
        'database/migrations/2026_08_27_020000_add_manual_fulfillment_fields_to_shipments.php' => '44d755cd840a87bbc96c48311f53751c6fc375f85d1fff5293090b5b8c2be0ad',
        'database/migrations/2026_08_27_171200_add_warehouse_ownership_type.php' => '758912e9318255ee88471503f3a1785f1430479e371e17f1add06e1d5cd5edad',
        'database/migrations/2026_08_27_181000_add_woocommerce_inbound_order_contract.php' => '24ade80cd3e84b05ac8c170b80d2120a479d59d6faab522bebb2fa0f4bc1b252',
        'database/migrations/2026_08_27_210000_create_kols_tables.php' => 'cca66cb015a6493a38f78ab8af0bfad144278d2565e86a27e63f4b124d28ed6c',
        'database/migrations/2026_08_27_210001_create_saved_views_table.php' => '848c4971ba83242e78578a373b78365782eb16c5a22a8af4e4755bd50c449602',
        'database/migrations/2026_08_27_220000_add_contact_shipping_payout_to_kols.php' => 'b1dde67cb168f0bcbef7d6f4c6820db054d8599ef32c1e0a3aa0612980115b85',
        'database/migrations/2026_08_27_230000_add_business_profile_and_contact_upgrade_to_kols.php' => '6ad45d9779d5233bd8c7f9f35f1bb13b74442aecef54410c5c33950b4e85a667',
        'database/migrations/2026_08_28_000000_create_sync_orchestration_tables.php' => '986373a6aa616b074c1edb119d6a4b2b30ba686170efae4374772d67749aa7c9',
        'database/migrations/2026_08_28_130000_create_kol_opportunities_table.php' => '232d1cb93ea17d3493651125d7d7ccf112a0d0bac4e7ca01e9aa3a8551efaa35',
        'database/migrations/2026_08_28_140000_create_kol_communications_table.php' => '8d616ba4c7114ff8a8455858cf6ccd9843ee0535e8022349f68cdcde70bcc5fa',
        'database/migrations/2026_08_28_150000_add_self_profile_fields_to_users.php' => 'abfbbda534229b2c5c7efcf5814ae76b212876a8157fdf8009e72e076d89b7c3',
        'database/migrations/2026_08_28_150000_refine_kol_business_profile.php' => 'b638db314c4752f609928bf3987425c68c5a096cd39ef9f424835171c32c2dbf',
        'database/migrations/2026_08_28_160000_add_avatar_to_kols_table.php' => 'aa1959bc10b7968bab270d0f4f61d9b2acba81a3d2cbcab2a2b850d6b04f428c',
        'database/migrations/2026_08_28_170000_add_direction_to_kol_communications_table.php' => 'e4027781e1f13e023a3ef30828996a98453ee367f9e60b425f6576efec0c8c1f',
        'database/migrations/2026_08_28_180000_create_kol_collaboration_tables.php' => 'b7ed969a02b22789e867fae0c6571f6ec730837235d7d8c37382d1af95c37860',
        'database/migrations/2026_08_28_180000_create_shipment_carrier_billing_table.php' => '4f1831fe201ee1f0b82f5dbdee6fffa72ccf01c752561e72b6563e2ce4aa40a0',
        'database/migrations/2026_08_28_181000_add_post_url_to_kol_collaborations.php' => 'd23e755f52b573a643e9e3817203a4c2b2a56dab2c31c42922b614d7a75d3127',
        'database/migrations/2026_08_28_182000_backfill_kol_collaboration_post_urls.php' => '75fd066462b688a7139784513cff15da24e4516c59a2bfdc8e460095a3863f98',
        'database/migrations/2026_08_28_190000_add_domestic_flag_to_warehouses.php' => 'f5afb1fbd1ae9191a9ae48063c878db1b3e4f050f7aa1a9655a582dac3e7f059',
        'database/migrations/2026_08_28_190000_add_order_id_to_kol_collaborations.php' => '8cd8bb15ed7aa905b9dbeb01ec4493b86875f1d9ef577d56e9b3695464b4b6ab',
        'database/migrations/2026_08_28_190100_create_order_operational_markers_table.php' => '2ef5a0f6ce5b3149fc0ef81768fbd2e7c0d910481939bb7f243d43b4dd9d4289',
        'database/migrations/2026_08_28_200000_create_guide_articles_table.php' => 'ff333c405ccec886ee764fcf6496abcd53dcd0428d7addd65129d0554fc6445e',
        'database/migrations/2026_08_28_200000_create_kol_contents_tables.php' => '3aacb1a9dbfadb17eef69542dc18d7d3bdc98aad1eaa302086bd33279ffda312',
        'database/migrations/2026_08_28_200100_create_release_notes_table.php' => '1ad9359ac7185106b41677d4fd69eba3d4b5f84a222113bf24568e5aeb1161eb',
        'database/migrations/2026_08_28_210000_create_customers_and_link_orders.php' => '23b515334af28797b6ad5dd950f389392151b003994ad5437e3e5e5268ff2b18',
        'database/migrations/2026_08_28_220000_create_customer_addresses_table.php' => '573bba35c511937fbb4d24f328bbe68a10acb18e68193045fc2cf08bf0898b7a',
        'database/migrations/2026_08_29_100000_add_approval_projection_to_kols.php' => '8f05be8bdbfa6978f28ed6968aa9c7ef500c34dc6e41ea641d43c008c31d9b1f',
        'database/migrations/2026_08_29_110000_create_kol_approval_policies_table.php' => '5d1fa9995426b0b808435e9ba542acc10add326a1f7f101a070085fa5112886d',
        'database/migrations/2026_08_29_120000_create_kol_approval_decisions_table.php' => 'aa9aaf55f9b5440ded8a946ee81dc47441860e94c5c60e271cbaec751201dc9a',
        'database/migrations/2026_08_29_130000_backfill_legacy_kol_approval_decisions.php' => '807c0a2c9004f510974f7d67f3773b29d818ac4b690b50175f93145ac9cf3787',
        'database/migrations/2026_08_29_140000_create_kol_affiliate_accounts_table.php' => 'ad374db46a91e398c785bd56ea14945c65bae9ddadfed731cd331526d6df11a0',
        'database/migrations/2026_08_29_140000_create_user_ui_preferences_table.php' => '6f76c7eb1cc3aa4d1eeac985787b07baca3b11b6d686e85a9500346bd5880bd7',
        'database/migrations/2026_08_29_150000_create_marketing_subscriber_tables.php' => '134de0ec084445828ad413b689253607e9491f7d65c50582d24d7013a9238373',
        'database/migrations/2026_08_30_000000_create_support_tickets_table.php' => '8a9edb6cc1713ed455062e83ea807529c0614072553a206ddcccf4a7b2bedd74',
        'database/migrations/2026_08_30_010000_create_customer_groups_tables.php' => '66397ae9eaef8fbbd2a97a7522ad60cc73e9ae4eb331fd3882f2d06fe87e306f',
        'database/migrations/2026_08_31_000100_create_customer_import_batches_tables.php' => '7334c12a78bed3d375029957fce4d529f7d992410727a47d88d19bdc1ef1d6e9',
        'database/migrations/2026_08_31_091500_create_wordpress_connector_settings_table.php' => 'c6f790f4f40820f0bd02ce8fa75400728538f848fcd5ac7a942e5088097cc07a',
        'database/migrations/2026_09_01_070000_create_kol_affiliate_coupons_table.php' => 'ab63302fce3805ff71e60ab9b77c290209cc1c93769a6b110262d26d19360aff',
        'database/migrations/2026_09_01_093000_create_kol_affiliate_referrals_table.php' => 'b73e73768eb4679e852f077d73daec7207a5eda2117fa583d37fcd7700d73ae2',
        'database/migrations/2026_09_01_100000_create_product_sku_aliases_table.php' => '8f8f6122d4955f73efe5ec2d95f56ab4a9f4a571adfb2c4e0acc1d7c7dc6921a',
        'database/migrations/2026_09_01_120000_create_kol_payout_workflow_tables.php' => '538f4e0bf8783abc068906db2edabcc9fa0192cd4c1d9341bae5406a3e8f8ab4',
        'database/migrations/2026_09_01_131000_add_kol_manager_approval_center.php' => '8d4248e613982994ee0dfc7abd31f9262a2d5982ff5c463f52a1c32060c2cb15',
        'database/migrations/2026_09_01_153000_add_kol_responsibility_owners.php' => '7d4d54083b979b62f545e47036edf93c1abc819bd96d31134e9f2966a9e65b6a',
        'database/migrations/2026_09_01_160000_create_order_identity_settings_and_erp_ids.php' => '01ea9fd8f40297da8fc2c8fb1b5230a190e2f374c64124baf002fc015f90c501',
        'database/migrations/2026_09_01_200000_create_product_weight_evidences_table.php' => '1757cc30d5996342bf017349c57acef17bd3e55e95e79d8bd8088271dd962df2',
        'database/migrations/2026_09_01_210000_create_product_external_references_table.php' => '640d1f30d6b8085c3ed23955a03b1c19c892d4824646dbfc027f3803e0916b83',
        'database/migrations/2026_09_01_210000_register_backup_permissions.php' => 'f775e592182a1ae1f7635538e36e4f9c7a5f49c98a076db85c88eb81d0661bfd',
        'database/migrations/2026_09_01_220000_create_backup_runs_and_artifacts_tables.php' => '46c1da578ee91cc7d3e80e93c3a464b9ad4e866e053e373fe46a67882ec3dc54',
        'database/migrations/2026_09_01_223000_correct_us_warehouse_names.php' => '06376276bb20e1fadc7f758c038d1b2281387bcedc8db80116cd5bbb05b7d173',
        'database/migrations/2026_09_02_100000_create_backup_schedule_settings_table.php' => '7e5b7aba56f90b4e52321e1f3049c0f0c476b7890c692ba9f8adc13dea2f3fd2',
        'database/migrations/2026_09_02_100000_create_kol_reconciliation_tables.php' => 'aacf2ff6326107e4315cc5c657e035d03b22428659acbd6723a645032ffb91aa',
        'database/migrations/2026_09_02_150000_create_kol_import_preview_tables.php' => '1da4ea9e9d479dc5ebaca9e228306b25f15512330b43cb49d143b0f00c5cb77c',
        'database/migrations/2026_09_03_000000_create_product_provider_snapshots_and_media.php' => '8caf887a13702ee4c920f34c97c7ac9b754d2e0137bde9416882335a877ee6e6',
        'database/migrations/2026_09_03_000100_add_product_id_to_sync_run_items.php' => 'd9fe7220a66f3d56a8bc129af4d17fc7bb1ea4989e0d776bcb7a4050d643e559',
        'database/migrations/2026_09_03_103000_create_kol_owner_mapping_tables.php' => '3024f7f73fd30d0f26eda804d40aefb9b83f10e617431395074ae911b173bddd',
        'database/migrations/2026_09_03_120000_create_packaging_measurement_evidence_tables.php' => '7b71fd96588112e1523126a2d5d656b9b603de5a620a1c7e0fdf6bce1eca0ce1',
        'database/migrations/2026_09_03_121000_create_product_packaging_profiles_table.php' => 'aba83d219e3488ad22bef6c0c6cd7385db1d9b06891de511006976b270b348c3',
        'database/migrations/2026_09_03_230000_create_kol_import_activation_tables.php' => '8e3e6b138289d8df09e7934ae2b9e336b2eb75d9c4ad0c9402bb0d4852e20c02',
        'database/migrations/2026_09_04_020000_create_kol_affiliate_binding_activation_tables.php' => 'b034d361cad8b0a28e67594fbbc3e92fe13c6b5ec4e5858eace5d162a382719f',
        'database/migrations/2026_09_04_090000_create_product_packaging_profile_media_table.php' => '9bf43833c8f54ee1264243f833644a067321812930b0ee1cee10684b4f3b5b2e',
        'database/migrations/2026_09_04_100000_create_shipment_item_packaging_snapshots_table.php' => 'd74e0f0f1162bba0d665dc12dab06a7966db0c5c009f8fbbedecaf21fe36e3b2',
        'database/migrations/2026_09_04_130000_create_notifications_table.php' => 'd77239d320f2597ad8e347aec678ea8ab50c0c08f03a53a82b8d0470f7582a16',
        'database/migrations/2026_09_04_140000_create_procurement_orders_tables.php' => 'dca0865643cef10a063b03c8fcc258057a0672e1e13bf0cfa076010f7a9d237e',
        'database/migrations/2026_09_04_173500_extend_procurement_orders_workspace_fields.php' => 'd6e47588d6e812c3f7ca420f158019d8d952482964e22f79790c28f30bd249b2',
        'database/migrations/2026_09_05_113000_add_procurement_completion_fields.php' => '357f17ec1bdbaa24ded229c83da451aa1cadf8c381951fa8676f78762035dce9',
        'database/migrations/2026_09_05_121500_create_logistics_carriers_table.php' => '739417fd101c8538d18e8f801283de236f726be33ba3b03e82fe5e9c0e8c5ddb',
        'database/migrations/2026_09_05_130000_add_wordpress_user_login_to_kol_affiliate_accounts.php' => 'f06b8248951382e129252d5df92f1667b789a3e53acf1096728ae7901f8f1137',
        'database/migrations/2026_09_05_130000_create_logistics_review_and_attempt_history.php' => '58619af11ff4e8b6f8f25cd5448d3c7e6dc7f88af0038076f340f2babb8a4833',
        'database/migrations/2026_09_05_160000_create_inventory_reservations_tables.php' => 'dedaed09bbf0b8e9244aca098c1934edc0d7c570848c0c6ade4beb9a3d41b890',
        'database/migrations/2026_09_05_170500_expand_order_address_line_2_for_woo_history.php' => 'eb110853a19c8e6084cc7425001d4092b6101efe85e874e25a6bd69390ffc967',
        'database/migrations/2026_09_05_171000_add_replenishment_policy_to_warehouses.php' => 'fb051a4d03a01f52b1e734b0a1d2a2efd1e09576d66f82b592b647e151c54ee7',
        'database/migrations/2026_09_06_180000_add_order_profit_evidence_contract.php' => 'a80d69865ef2351332e93840a9f2d227cf2a5c34e3830bd81300ff3c2d385ccc',
        'database/migrations/2026_09_11_200000_create_business_projects_tables.php' => '308d7148cdfb92d2a680264e062f1c4b3cce9bb4577bc330019913d7cb2aaf1f',
        'database/migrations/2026_09_11_200100_create_traffic_tables.php' => '1d906571872f99f3958e97469e0d34e26aeaae35d0db886b21251b8743233f46',
    ];

    private const FRONTEND_FILES = [
        'resources/js/Pages/Finance/ProfitDashboard.vue' => '806e8727de4919f216511d32134556e2a6ad3aaadbaa59f40a28784a7e5cdae8',
        'resources/js/Components/Logistics/StatusBar.vue' => '5a1e0b77fc5b8972d48083fb6e177df8a3f5c3facb13233e469effec5afbd2f0',
        'resources/js/Components/AppShell/GlobalFeedbackIsland.test.ts' => '36d4310edf520357dd153cc19de772d774e3473606881298d734ed28e1725ce1',
        'resources/js/Components/AppShell/GlobalFeedbackIsland.vue' => '17e7bcf0d540843934215cb537b82d243e3492b0760518f523500c15b1916cd9',
        'resources/js/Components/AppShell/GlobalNavigation.test.ts' => '49d9b31f8a296a545117a6593de4eba26126c5a9865b5df9c39b6dd5688d5e49',
        'resources/js/Components/AppShell/GlobalNavigation.vue' => 'd770da59371440e8b4108aaa4116f50f07baa0b1980292d203a7936d702bf6dc',
        'resources/js/Components/AppShell/ModuleSidebar.vue' => '127f19089943410e88558a4f4fe07f7003c318c353b29582d526bdfcd64bc5a4',
        'resources/js/Components/AppShell/NavigationItem.vue' => 'a01afcb25099612ce549b48f3504f998c3ab71a112576e88792c5c1f99f4ead2',
        'resources/js/Components/AppShell/TopBar.test.ts' => '2b49fac1367891c39b9202cf4d3c8d9b8eba7ab4a767e0be5b7c43f8ee6f9d09',
        'resources/js/Components/AppShell/TopBar.vue' => '1a4b9a6b31fee11bacb42abe74215e34cb0b3b7e798647a2765ef449db8d8a7c',
        'resources/js/Components/AppShell/UserAccountMenu.test.ts' => '4f5d8af12c7be66fa9a878f47f614e2d6e734e338aa71ed6415ed9caa93c18db',
        'resources/js/Components/AppShell/UserAccountMenu.vue' => '3f19fd436b22405f12bf2070565e50eb15268f7deb1eeb7160afa122eb1ffbaa',
        'resources/js/Components/PermissionMatrix.test.ts' => '5050666c6cdb788a74033ca42d7b9e0a09003faf5478992ce54af022f2a76839',
        'resources/js/Components/PermissionMatrix.vue' => '5376899d0142e95e8af4ceb218ea552d5495b733059e24e6165f7bc74b166a5f',
        'resources/js/Components/Products/SkuCombobox.test.ts' => 'fe5a4a4faf285ed257496aab9f183541df6ef3fab1cd708e410e9feca278c6b1',
        'resources/js/Components/Products/SkuCombobox.vue' => 'f2b5e07e5621b2c4e0184eaa308379d0185bad20efbd2de9bf0531fca7d9433e',
        'resources/js/Components/SearchableSelect.vue' => '9ece77235645ae4bd1c19bff0d01a408b5d333ae653b1bb34c1ce567f2939faf',
        'resources/js/Components/UI/EmptyState.test.ts' => '4fda8290eb407fe9313f32d5a5ac0f802cbb668797af87b9cb3c7f856e528b18',
        'resources/js/Components/UI/EmptyState.vue' => 'a0cab2c71afa978cca886b9b6cb9ab0f0ff40d29962109f7da7c5e1aed2297ce',
        'resources/js/Components/UI/FormField.test.ts' => '9e604341a19c1618e5949b713c6137cbc27c50c64f0830890c2a50cc2e38df4c',
        'resources/js/Components/UI/FormField.vue' => '681c3d841736873ee50fa7528ed3d82fe54452b927d76be1ccca9eed1b9dfe2a',
        'resources/js/Components/UI/TextInput.test.ts' => 'a379b41444e9f3995cc59506022ab463201da0ec15250afe15a5adb2adee4427',
        'resources/js/Components/UI/TextInput.vue' => 'bab268f21dcb88a55c8ebf85821bf7121cf1eea017645319afe3bb1e086a3539',
        'resources/js/Components/UI/UiAlert.test.ts' => 'af5615f3a4d0eeae39dbaa785820959f449ce518e90d881da4608e9dcca2e015',
        'resources/js/Components/UI/UiAlert.vue' => 'e3e6ee0e127ae0911efce1bb1d1cb873146cf77220c9c9156a86b968b03b519f',
        'resources/js/Components/UI/UiButton.test.ts' => '126bc019e025164dc901b9c21986aa370fedf26da3d1618c90d7c60791797bf8',
        'resources/js/Components/UI/UiButton.vue' => '7ae624f92c56911ce58b60ae54c54625e85837666ca6b9882ac04be32c718113',
        'resources/js/Composables/useAccountGridPreferences.ts' => '2116e19fca6072de4fb55e5c57785340e2e74a39ee1c16bbbb2214b3b41b9620',
        'resources/js/Layouts/AuthenticatedLayout.test.ts' => 'f43ad24ee595b6203987fd69746a9a3eae98f0e5619e70c19a91b5e3555d3fde',
        'resources/js/Layouts/AuthenticatedLayout.vue' => '45a285e6b2093e80f700012a3a8a46c7d73124cdabccaac7b0d917dc888ac8fc',
        'resources/js/Pages/Architecture/Foundation.test.ts' => '68898785345d5cc641bcc8b3f165527a94c912e33c5f1941554a9a28aad97a1c',
        'resources/js/Pages/Architecture/Foundation.vue' => '0f3f96bd3ad9a5b68fa7fe5a78e20a1e0e92f4d2e6806ef30697fda64941aa70',
        'resources/js/Pages/Auth/Login.test.ts' => '445f3392872df2466f83736af7854a1a5c2e477198abea252c546e24141420c8',
        'resources/js/Pages/Auth/Login.vue' => '5595bb6472df208ce26f74a7e8ba51dd3d96d1421c1502517af97827a3d8b9db',
        'resources/js/Pages/Backup/Index.test.ts' => '006602b2eec26c8fbc5e0422ecc488f831ad2c377538b94598b24f98f39b7563',
        'resources/js/Pages/Backup/Index.vue' => '249fb730893bb197fe03fe6ecc8419c18e694768d93874cb1217a71c6c57e335',
        'resources/js/Pages/Customers/Companies/Create.test.ts' => '2221b8d4e063e0615a97af14e6fbb738022fd44b55080a6fa6fa8c14d500e198',
        'resources/js/Pages/Customers/Companies/Create.vue' => 'c9b4830fcaf4a5a7ec3748bcbf515c265f33999d15460b749a69c14168a525a4',
        'resources/js/Pages/Customers/Companies/Edit.vue' => '4bcd7f0f42345623c5b2eaeba7cf625a585b2a291df9f8b53cb9446f9d14958d',
        'resources/js/Pages/Customers/Companies/Index.test.ts' => 'ab4d2c8f2e3f751daab6b8f980607251e31f67eb646539e593755386823713ab',
        'resources/js/Pages/Customers/Companies/Index.vue' => '27479209ba47fca2c4601b6d46c993d150698574ac2edb84ffe85e2153db2eeb',
        'resources/js/Pages/Customers/Companies/Show.vue' => 'a7677a9242192530060322275fb75b27387359e94b4e6c3794da06257b43ba8f',
        'resources/js/Pages/Customers/Dashboard.test.ts' => 'bf4f07b21d0c6716636e9cf5d822b3dba7a00b116ca679ad7b2ee12ec131ce22',
        'resources/js/Pages/Customers/Dashboard.vue' => '3438a0e6990d97036966b0a7b72e67a6841cd52ec0f0ee8f0518cdf2d1947553',
        'resources/js/Pages/Customers/Import.test.ts' => 'ef9fb4ec8aff804ac4af097261aa84505f7f5ab47fef9dbbf1cf393c47dd19be',
        'resources/js/Pages/Customers/Import.vue' => 'aa82c8954e635a14525bd1bc30808edf5617e229226a70265136e4b85a928b19',
        'resources/js/Pages/Customers/Library/Create.vue' => '17066bb7a7f0dc1ac21f539f79f79397990b08574b69952171683975878d50d6',
        'resources/js/Pages/Customers/Library/Edit.vue' => '1b99107357f6979f860c72457bfc01c9f5bf7f3c39343d96afe946f48774895f',
        'resources/js/Pages/Customers/Library/Index.test.ts' => '8f23ecc6ac59b26913c3d89ebc0da08bceab632cbef752f0790d5358ee840bf5',
        'resources/js/Pages/Customers/Library/Index.vue' => '20469eb45b70d5caea9adcd9b9c3081f3a3ba1482f9471d41f2bd602fee663bc',
        'resources/js/Pages/Customers/Library/Show.test.ts' => '9649767373fc8eec7d688d6443f570b5b708e3f0c1937181931a6c1869f6e835',
        'resources/js/Pages/Customers/Library/Show.vue' => '8fc3d78a88c94eadf1b964b022f36ba1738a8fed52d011ce40a003f9143f09ef',
        'resources/js/Pages/Customers/Support/Create.vue' => 'b22761d3b7a22ecc96bf5a8ced4019a5d5410924d126876f4a36b5f2eef0e075',
        'resources/js/Pages/Customers/Support/Edit.vue' => 'c62bf6df55d1d63c6006556023d8fc5f2fcbcc03ae787ad96c416339db7bb6ae',
        'resources/js/Pages/Customers/Support/Show.test.ts' => 'c3ce33293b3a819073b6cca47ae794f51a517c66373615f6d35fb5b953f7c707',
        'resources/js/Pages/Customers/Support/Show.vue' => '05fcbda0b6402a1dfcad552897e9e7d1fe458554f0235c1ba9d83c9f98bfa807',
        'resources/js/Pages/Customers/Support/TicketForm.test.ts' => '4e10f9cd2ac487fe0635ceabe1d8855ffd8309043e43eff50355f9475bb05184',
        'resources/js/Pages/Customers/Support/TicketForm.vue' => '28e2198067ef12bd82ad19765e8a6a7fd4c5761211bce16f6a89de6898dc1c05',
        'resources/js/Pages/Dashboard/Index.test.ts' => '27c63e094fcdec28a0e5677508723e149c90a5130efb98d4448fa2b7f51b4ae4',
        'resources/js/Pages/Dashboard/Index.vue' => '0c28a768afcaa181b54ab01c4ec08457a04f7ed98e5ba3b52a041d98492bc2bb',
        'resources/js/Pages/Finance/OrderAnalysis.vue' => 'faab24eb5dc6d131d8bf89688c529949b7df237e9ac66049309c59aec7570f85',
        'resources/js/Pages/Guides/Edit.vue' => '47b31eb218c25505b2037e27460f99f52c80d42eee4057583d2ea680e9bd890b',
        'resources/js/Pages/Guides/Index.test.ts' => 'b751aca1f6cc636b969306643ad1278159dd3be28192c129df9033a62f92e0c9',
        'resources/js/Pages/Guides/Index.vue' => 'b4121443c078f0f386cbe204673386bf75d73fd187b9f642e61a05adabcc35ad',
        'resources/js/Pages/Guides/Releases/Edit.vue' => '4bd76aa0a3bdae2ba74a0d601259cda0c64a41b37f836b19faf2f14dd0fbaca2',
        'resources/js/Pages/Guides/Releases/Index.test.ts' => 'f87f3e583d97aadaf3fc77bd23a5793b48bf4bc66463112fc14d9fe11021ed2c',
        'resources/js/Pages/Guides/Releases/Index.vue' => '494bcc2c00688d1a95a398a79ceb016d1ee79e892371c5d861b141c04c0fb179',
        'resources/js/Pages/Guides/Releases/Show.vue' => '1186bb25382dcf06851b01f265753f5ce265a5503b28d9cf02cb00f8bf276eef',
        'resources/js/Pages/Guides/Show.test.ts' => '3dd81f33f5aa7303c7c09dcd9f7acc68ed57cf240025594a2322403fa670a1c0',
        'resources/js/Pages/Guides/Show.vue' => 'd8e6205a05d65bbb3e4aa823b762f4424114f65ad7c2550ac72374ae36d3124f',
        'resources/js/Pages/Identity/Account/Password.vue' => '515e95bbdad3b138f69f96676aeb176dd3554b048685c3e54da2897844ab2887',
        'resources/js/Pages/Identity/Account/Profile.vue' => 'cac0dfa2facdc852dfc9cc44d3897c6910eaa4d68abbd4edf6ff5091b741a85d',
        'resources/js/Pages/Identity/RolesPermissions/Index.test.ts' => '70626acf8b25f93c5a979be6f6f493113445ec0778e34fe3b1d26f8983b195c5',
        'resources/js/Pages/Identity/RolesPermissions/Index.vue' => '2c9adf03bc8415cef556abdb0c7ae6144f34178e073ac6bebd245829b40e9aa3',
        'resources/js/Pages/Identity/Users/Create.test.ts' => 'a4b6e2d0cf07dc79c9569e6bd8e691ba0e8892cab7df55d62f62a9a029039d71',
        'resources/js/Pages/Identity/Users/Create.vue' => 'd22144f41e2a86b36858a61fec317cab9d11eaa25eefb7dccdce65d166df1c5e',
        'resources/js/Pages/Identity/Users/Edit.test.ts' => 'c8653e756df55cbd1608ca9b0bcf4ab63b6973bfe1cb0f2e0ac6fbce7226d4e8',
        'resources/js/Pages/Identity/Users/Edit.vue' => '50d4a515a8b2425f95e4cc8385b441faa0c14c1d378c8d9cacc67a266a30fda0',
        'resources/js/Pages/Identity/Users/Index.test.ts' => 'c2a508b3fb59d1f4725f86d658bfdcd162487477cae6c7f5e0db119b2f4f2cd0',
        'resources/js/Pages/Identity/Users/Index.vue' => '559f700abcd95b484fe834dbb0e5f871cc7301ff20d0e51f9c3c1179ea228e07',
        'resources/js/Pages/Inventory/Dashboard.vue' => '095a36fba2dbb525c5cbeb76dc0aee0e046749350b37174e7199e04a37463a29',
        'resources/js/Pages/Inventory/InventoryBatchSync.test.ts' => '3c63a7717dcac8e4698db4fd922f69867c82ff9667b0c0f0f03b2b9045c5375f',
        'resources/js/Pages/Inventory/InventoryFoundation.test.ts' => 'f96bf47f65d5886863f5662ee993138f4918d4d201cb46aac5356865b37143e1',
        'resources/js/Pages/Inventory/Products.vue' => '9f24a42057a68f365679e01c5c66764a4eb95ba2256df1b02cca9c6c60e4f427',
        'resources/js/Pages/Inventory/Sync.vue' => '3ec525e92c95252991a05999a539eab3d301a5d13e4c9512cde7a4249b8f044a',
        'resources/js/Pages/Inventory/WarehouseCreate.vue' => 'e980fa22bdc475729f99c5fea9da811d93aae964ff719d2d6f549a41c0885d75',
        'resources/js/Pages/Inventory/WarehouseList.vue' => '7f3bf94a895c719320525aa82dd6fbbb367dcd436ac483eed0b179ec8c4c85d0',
        'resources/js/Pages/Inventory/WarehouseShow.vue' => '99148b15610e1dfd7fd2558c2898d644f9a88579add7888c56c58ccca5c50ae8',
        'resources/js/Pages/Inventory/components/InventorySkuCombobox.vue' => 'd2d7408ee79d6fe9b060c268cf076a33a6d30ce096cc1d56ab9d49767e1bd670',
        'resources/js/Pages/Kol/AffiliateWp/CouponPanel.vue' => '606d6b6d13f4644a7717fb57803666ea670f57571e54fd7f9552a2792b2d2751',
        'resources/js/Pages/Kol/AffiliateWp/Index.test.ts' => '82aa94b276ee3c0d2c65178b3a107a850240dc215762dc15316facaea20f12f8',
        'resources/js/Pages/Kol/AffiliateWp/Index.vue' => 'b24e8805db9ba85c41fa4662529128efb8fb49652932cffe55c93f41b4e9855c',
        'resources/js/Pages/Kol/ApprovalCenter.test.ts' => 'c6464dacb224f4527d10fb2570156415b676ee36e8cab658fc7547446a253831',
        'resources/js/Pages/Kol/ApprovalCenter.vue' => '5b3b0ba757a6f73768d5a5b3f9958e1c2856400dfd9fee9bce030969a0fb7c4a',
        'resources/js/Pages/Kol/Collaborations.vue' => '1093306b4ad34da1fda2c858e90acef6abf8f0e6d32cc751303bb4765d82bbd3',
        'resources/js/Pages/Kol/Commissions.test.ts' => '240d1a3958fcd78304300221d3ecec791ff30a904b8e8513c3e5161add716674',
        'resources/js/Pages/Kol/Commissions.vue' => '177389055fe9909565fe3156c695d6df769da39bbf92a3e08a7dda1f5708c993',
        'resources/js/Pages/Kol/Content.test.ts' => 'b409dc025aae7551e5b65c8eb3c6795d7af9b511f4bade317b140b71ca0fb678',
        'resources/js/Pages/Kol/Content.vue' => '0d539d3e346cb5ebd57ff09b2c1794ac3c6f9b427ae78621896907133dc57de5',
        'resources/js/Pages/Kol/Create.test.ts' => '2f0beacb9c7f6a6cec7ffc210c43a9147151e34455f7be1a81e6bad24d861d56',
        'resources/js/Pages/Kol/Create.vue' => 'cfb0858cf2645552ef83763e65faa9901e9f96de032879db460aa70548881d56',
        'resources/js/Pages/Kol/Edit.test.ts' => 'ef1a872ec59cd741a2518f3a466c753040d9002bf4580ef042d313dd5d692b9e',
        'resources/js/Pages/Kol/Edit.vue' => '9131e288e623e9843c282376f31160a3decbe9a680226cfa077ac8b1ae312ef3',
        'resources/js/Pages/Kol/Index.vue' => '870cf52c074c94cd987f974776d905129c90f1053bad7680b5388ae57c904d09',
        'resources/js/Pages/Kol/Overview.test.ts' => '046504dd56ab291596b94c02c97756cb44c6a1b25aadae43ec16f9f555757777',
        'resources/js/Pages/Kol/Overview.vue' => 'a86926f15e53c016879667de47ea285caeffffd7c7c64df00b1745daf43ae131',
        'resources/js/Pages/Kol/Payouts.test.ts' => '0adf3e087814b9f5667819066dcb052b1ed064aaf1438241598cd832b9f3c67c',
        'resources/js/Pages/Kol/Payouts.vue' => 'acba8cffda19a33c8e5ffaf3e618cc032f4e229ce135e26b65c2c2ba4f2fc081',
        'resources/js/Pages/Kol/PlaceholderPage.vue' => '17bcfd63e0f10267eccb24ff899ebb61d04265b1caceb5a35a7023c633fad595',
        'resources/js/Pages/Kol/Show.test.ts' => '4febe862caf8a8946af20c8c4f603e7f90e64ebd5db165d810e98b7ea1005b89',
        'resources/js/Pages/Kol/Show.vue' => 'dd08b59c07e1a04a82270951947359f74da9bcb5a0109e9c7dc382b85f7f611f',
        'resources/js/Pages/Logistics/Carriers/Index.test.ts' => '249b948ccd18672168875064728f3fc73902f3c70d1c6481bd16c2f74bc03574',
        'resources/js/Pages/Logistics/Carriers/Index.vue' => '4285fae56eec289f35620026938e49eb529cec41573bab96344fb5bfc9c9f921',
        'resources/js/Pages/Logistics/Carriers/Show.test.ts' => '5293a388312a9123449ff8ea54c9c3bc602028f71901d8ba243a6245b342f5ea',
        'resources/js/Pages/Logistics/Carriers/Show.vue' => '0cb4a23976aecf89c45f704cce8b49ba174662585be4c877bd3bea58d5b9c559',
        'resources/js/Pages/Marketing/Overview.vue' => 'cd8cf27254d675e43cac6e685f45c47472027a098459bfac6c27c189861a4117',
        'resources/js/Pages/Marketing/Planned.vue' => 'e19e425b047284a1ce9931c87c2346c5d30578de22c8b604ec7d431cd9d4718b',
        'resources/js/Pages/Marketing/Subscribers/Index.vue' => 'ba63d758645327b01a630dbe7e34828627a4682c1424bf5706d403c14676dfd7',
        'resources/js/Pages/Orders/Create.test.ts' => 'b39b0212f7ec61fb635eb1b3e70d44df7496e23c13aa04ccb491edb4228f7c12',
        'resources/js/Pages/Orders/Create.vue' => '1e7185023ff1783409776f62c2a4a767026da800cdde4d0b02f6a99af5b6ada4',
        'resources/js/Pages/Orders/Index.test.ts' => '6cf15671f78ac76876718fc84d8272b5eaecfbe11189d0b7f1f1cce5083671dd',
        'resources/js/Pages/Orders/Index.vue' => 'b425f08bdea83d97070720fea6ab4ccfe0caf6c9bede75aff54d15649bf8036c',
        'resources/js/Pages/Orders/Show.test.ts' => '997eaf32f8d08c805a10d205b5b8dbcca7c2e4d5c6af164027311652c1838c4b',
        'resources/js/Pages/Orders/Show.vue' => 'e8dbf525d8dafeefdc6a0131739c2c2446843a13b64adb7bc477111ff34daa61',
        'resources/js/Pages/Products/Create.test.ts' => '5be67e88543a49632c107677ada316c3820ed0cb2b1d3d63c66604e3a22756e9',
        'resources/js/Pages/Products/Create.vue' => '419a8458888d9e915fe6af5f562a9247798225c3edeebb4cbf5ba6289461baa4',
        'resources/js/Pages/Products/Dashboard.test.ts' => '94c55c75db32ab0ef154fda039b3735946898912939a8022ee1a4a3a59c97997',
        'resources/js/Pages/Products/Dashboard.vue' => 'ef523319db49a1d4bd970be5e7d700d7d5d1ed418f8e36c1e93e2a3477f309b9',
        'resources/js/Pages/Products/Edit.test.ts' => 'd035221655fce0bbce69d9a035086fc93f110cad30216cd5391c4cd408352e93',
        'resources/js/Pages/Products/Edit.vue' => '957ea4b6e33c4bb15399e4d2ec508d9b7622b8ba29b486b716c18174a8e57a51',
        'resources/js/Pages/Products/Import.test.ts' => '1eac3545b4321c340e5f451ad69c6a78ed43f1730e8271e7f7feea97b7a0eaf8',
        'resources/js/Pages/Products/Import.vue' => '8d8cf9de1dbcfbbf572b5c4e25ad074260bc6f45ae94453d77595c1ca53e3442',
        'resources/js/Pages/Products/Index.test.ts' => '8d667ef2f06bdd8e78c2b66ad57b843a9cb605a1bc51a5763bc22fa2f5fe48df',
        'resources/js/Pages/Products/Index.vue' => '644fb6876ec0dbe3cf53988a037b449a4fb4a27565376dfe1e9517aacff91aa1',
        'resources/js/Pages/Products/Show.test.ts' => '0278c2b10f70b13c85288462dc4c18a21fa7231a3f309c38cd0992a1ecc50d1c',
        'resources/js/Pages/Products/Show.vue' => 'b6247899a67d88453e138c2b60a8cbdfc31dd258a2a66257fd8ad3bf44825454',
        'resources/js/Pages/Products/SkuAliases.vue' => '4b3fd62779ce2197d167f793671568949fe16c6f5f9f94f9616f4966b889e840',
        'resources/js/Pages/Products/Skus.vue' => 'e4f98aea0088f222d69d93ace754d268a905c64d230b3607212aa2a063b3b10e',
        'resources/js/Pages/Products/WeightEvidence.test.ts' => '5b28350ba9030846eb3ae96a97435048818817252e1912ea936e3cd007e3fdd5',
        'resources/js/Pages/Products/WeightEvidence.vue' => 'cd44b849340934aed046c1692bf5f63c4d22e46af0f459a73d3e7c5cd8b5e5fe',
        'resources/js/Pages/Settings/Index.test.ts' => '45cbba34d62289d3bd7c99bf05b9aa099a2875425c93ae8e0d4464619a60a825',
        'resources/js/Pages/Settings/Index.vue' => 'b26498b265d054a5bf1ca8416f25bf43c3c208186838aa8ec65425b568319de1',
        'resources/js/Pages/Shell/ModuleOverview.vue' => '7c4148ed1570444f4b2cb5763a1a8cfac3c6f7b958b95a4f3d98fbf40b6ca02c',
        'resources/js/Pages/Shell/Placeholder.test.ts' => 'd0623492800b81b76650531eec2b979747d98daf34dcd4320ba00438529bb781',
        'resources/js/Pages/Shell/Placeholder.vue' => 'e18f588432b7d29a025f7618f977bdb7457536e8ae8028c2c343ce5c7b95472f',
        'resources/js/Pages/Sync/Exports.vue' => '932514b646b4ba57d800294d68bbcfd228ab69273139eb48e12b8940524fd6f4',
        'resources/js/Pages/Sync/Index.test.ts' => 'c7c60ba6a209996c1b816115225dd2ea4116d741caed18e8184e3a5cc6ac6e83',
        'resources/js/Pages/Sync/Index.vue' => '1f163d6e43fcadc2f29c1fc2ad9b1398227ed91f5dd3713b0cbba8a4ead97061',
        'resources/js/Pages/Sync/Kol.vue' => '2e14cf9d2e6e45e012154ea9c16a97f5552044198a08e62ee20019b7ca9f7891',
        'resources/js/Pages/Sync/Orders.test.ts' => '0dca497aca6740187deb113bd2e3be023da7e93a36905f9abb15315b980356b1',
        'resources/js/Pages/Sync/Orders.vue' => 'ac820592eb1bc897e87e3b2926a8bf5b07e79c12dfb92f2ffe6293b6f63a2f4e',
        'resources/js/Pages/Sync/RunShow.test.ts' => '9197c15ef1b151072f645fd9c6a5231cfe599c8376bcbee862045056f67f1cc2',
        'resources/js/Pages/Sync/RunShow.vue' => 'd21f6b55b871e5b5997524426f93da70bb5305b070ba630c79b110eeb220df1a',
        'resources/js/Pages/Sync/Warehouses.vue' => '59a2e6ae75d572c26f80bef5500979b02b20fc84756fdfe73110ceefbb10d06b',
        'resources/js/Pages/Sync/WooCommerceInbound.vue' => '5d66d34b3ba1aeaf910914d390797e776c25d1e2d91e33b572214628e10dc3d9',
        'resources/js/Pages/Sync/WpApi.vue' => '24026ee2b4d4f404f039ebf4cd383215a8b8d88f6ad3b77ac6476e26a4d43652',
        'resources/js/app.js' => '40994ac10f73e394b96d6e4742be37921fc38e477dccfdfe9e8ab90cd5e6d7d4',
        'resources/js/app.ts' => 'c667a849465fad821d6d08babe410c6d44bd7412629198522f1d408f373823b3',
        'resources/js/lib/globalFeedback.test.ts' => '63887c4a11ca91d7014df88987bf6ccbaf54be0c03dc334f78711fec3ff519d6',
        'resources/js/lib/globalFeedback.ts' => '54ba6e2214e691f2059e239571fb9f70a533b8b2dae7a6d8923f8a2ee43fbfc3',
        'resources/js/types/navigation.ts' => '265ec164e751beaa4c53045fa431850601840cbea6deab4e7a87de72e8538327',
    ];

    public function test_frozen_module_and_support_boundaries(): void
    {
        self::assertSame($this->sorted(self::MODULE_DIRECTORIES), $this->subdirectoriesUnder($this->path('app/Modules')));
        self::assertSame($this->sorted(self::MODULE_FILES), $this->allFilesUnder($this->path('app/Modules')));
        $this->assertFixedFileSet(self::SUPPORT_FILES, 'SUPPORT-001', 'app/Support');
        self::assertDirectoryDoesNotExist($this->path('app/Shared'));
    }

    public function test_layer_scans_are_limited_to_module_layer_directories(): void
    {
        $domainFiles = $this->moduleLayerFiles('Domain');
        $applicationFiles = $this->moduleLayerFiles('Application');
        $infrastructureDependencyFiles = [...$domainFiles, ...$applicationFiles];
        self::assertSame($this->sorted([
            'app/Modules/Audit/Application/AuditActor.php',
            'app/Modules/Audit/Application/AuditService.php',
            'app/Modules/Audit/Application/AuditSource.php',
            'app/Modules/Audit/Application/AuditWriteException.php',
            'app/Modules/Audit/Application/Contracts/AuditRecorder.php',
            'app/Modules/Audit/Application/Contracts/AuditWriter.php',
            'app/Modules/Audit/Application/CustomerActivityProjection.php',
            'app/Modules/Audit/Application/ProductActivityProjection.php',
            'app/Modules/Backup/Application/BackupArtifactCleanupService.php',
            'app/Modules/Backup/Application/BackupArtifactData.php',
            'app/Modules/Backup/Application/BackupEngineService.php',
            'app/Modules/Backup/Application/BackupRetentionResult.php',
            'app/Modules/Backup/Application/BackupRetentionService.php',
            'app/Modules/Backup/Application/BackupRunData.php',
            'app/Modules/Backup/Application/BackupScheduleCadence.php',
            'app/Modules/Backup/Application/BackupScheduleSettingData.php',
            'app/Modules/Backup/Application/BackupScheduleSettings.php',
            'app/Modules/Backup/Application/GithubReleasePublishData.php',
            'app/Modules/Backup/Application/GithubReleasePublicationData.php',
            'app/Modules/Backup/Application/GithubReleasePublisher.php',
            'app/Modules/Backup/Application/BackupState.php',
            'app/Modules/Backup/Application/BackupType.php',
            'app/Modules/Backup/Application/ChecksumService.php',
            'app/Modules/Backup/Application/Contracts/AutomaticBackupPolicyRepository.php',
            'app/Modules/Backup/Application/Contracts/BackupEncryptor.php',
            'app/Modules/Backup/Application/Contracts/BackupRepository.php',
            'app/Modules/Backup/Application/Contracts/GithubReleaseDeletionGateway.php',
            'app/Modules/Backup/Application/Contracts/GithubReleaseGateway.php',
            'app/Modules/Backup/Application/Contracts/DatabaseDumper.php',
            'app/Modules/Backup/Application/Contracts/DatabaseRestorer.php',
            'app/Modules/Backup/Application/Contracts/PreRestoreSnapshotter.php',
            'app/Modules/Backup/Application/Contracts/RestoreCompatibility.php',
            'app/Modules/Backup/Application/Contracts/RestoreEncryptor.php',
            'app/Modules/Backup/Application/Contracts/RestoreHealthChecker.php',
            'app/Modules/Backup/Application/Contracts/PostRestoreSchemaUpdater.php',
            'app/Modules/Backup/Application/Contracts/RestorePackageExtractor.php',
            'app/Modules/Backup/Application/Contracts/RestoreRollbackExecutor.php',
            'app/Modules/Backup/Application/Contracts/RestoreSource.php',
            'app/Modules/Backup/Application/Contracts/StorageArchiver.php',
            'app/Modules/Backup/Application/Contracts/StorageRestorer.php',
            'app/Modules/Backup/Application/DatabaseDumpAdapter.php',
            'app/Modules/Backup/Application/Exceptions/GithubReleasePublishException.php',
            'app/Modules/Backup/Application/ManifestBuilder.php',
            'app/Modules/Backup/Application/ManifestContract.php',
            'app/Modules/Backup/Application/PackageBuilder.php',
            'app/Modules/Backup/Application/RestoreCompatibilityValidator.php',
            'app/Modules/Backup/Application/RestoreFailure.php',
            'app/Modules/Backup/Application/RestoreMode.php',
            'app/Modules/Backup/Application/RestoreOrchestrator.php',
            'app/Modules/Backup/Application/RestorePackageValidator.php',
            'app/Modules/Backup/Application/RestoreRequest.php',
            'app/Modules/Backup/Application/RestoreRunData.php',
            'app/Modules/Backup/Application/RestoreState.php',
            'app/Modules/Backup/Application/RestoreStateMachine.php',
            'app/Modules/Backup/Application/StorageArchiveAdapter.php',
            'app/Modules/Backup/Application/StreamingAesGcmEncryptor.php',
            'app/Modules/BusinessProjects/Application/BusinessProjectService.php',
            'app/Modules/BusinessProjects/Application/Contracts/BusinessProjectRepository.php',
            'app/Modules/Customers/Application/CompanyService.php',
            'app/Modules/Customers/Application/CustomerGroupService.php',
            'app/Modules/Customers/Application/CustomerImportService.php',
            'app/Modules/Customers/Application/CustomerSegmentationQuery.php',
            'app/Modules/Customers/Application/CustomerService.php',
            'app/Modules/Customers/Application/CustomerSubscriberProjection.php',
            'app/Modules/Dashboard/Application/DashboardMetrics.php',
            'app/Modules/Finance/Application/EcbFxService.php',
            'app/Modules/Finance/Application/CostMaterializationService.php',
            'app/Modules/Finance/Application/DecimalMoney.php',
            'app/Modules/Finance/Application/OrderAnalysisService.php',
            'app/Modules/Finance/Application/ProfitDashboardService.php',
            'app/Modules/Guides/Application/KnowledgeBaseService.php',
            'app/Modules/Guides/Application/MarkdownContentRenderer.php',
            'app/Modules/Identity/Application/AuthorizationKeys.php',
            'app/Modules/Identity/Application/Contracts/AuditRecorder.php',
            'app/Modules/Identity/Application/DataScope.php',
            'app/Modules/Identity/Application/Exceptions/IdentityGuardException.php',
            'app/Modules/Identity/Application/PermissionCatalog.php',
            'app/Modules/Identity/Application/RolePermissionsService.php',
            'app/Modules/Identity/Application/RolePreset.php',
            'app/Modules/Identity/Application/UserDirectory.php',
            'app/Modules/Identity/Application/UserService.php',
            'app/Modules/Inventory/Application/Contracts/InventoryAvailabilityReader.php',
            'app/Modules/Inventory/Application/Contracts/InventoryBatchSyncRepository.php',
            'app/Modules/Inventory/Application/Contracts/InventoryRepository.php',
            'app/Modules/Inventory/Application/InventoryBatchSyncService.php',
            'app/Modules/Inventory/Application/InventoryService.php',
            'app/Modules/Inventory/Application/Sync/ApiAdapterPlaceholder.php',
            'app/Modules/Inventory/Application/Sync/CsvAdapter.php',
            'app/Modules/Inventory/Application/Sync/NormalizedRow.php',
            'app/Modules/Inventory/Application/Sync/PasteAdapter.php',
            'app/Modules/Inventory/Application/Sync/RowNormalizer.php',
            'app/Modules/Inventory/Application/Sync/XlsxAdapter.php',
            'app/Modules/Kol/Application/AffiliateWp/AffiliateBindingService.php',
            'app/Modules/Kol/Application/AffiliateWp/AffiliateSnapshotSyncService.php',
            'app/Modules/Kol/Application/AffiliateWp/ConnectorResponse.php',
            'app/Modules/Kol/Application/AffiliateWp/CouponProvisioningService.php',
            'app/Modules/Kol/Application/AffiliateWp/CouponSnapshotSyncService.php',
            'app/Modules/Kol/Application/AffiliateWp/KolProvisioningService.php',
            'app/Modules/Kol/Application/AffiliateWp/PayoutSnapshotSyncService.php',
            'app/Modules/Kol/Application/AffiliateWp/PayoutWorkflowService.php',
            'app/Modules/Kol/Application/AffiliateWp/ReferralCommissionSyncService.php',
            'app/Modules/Kol/Application/Approval/CollaborationApprovalService.php',
            'app/Modules/Kol/Application/Approval/KolApprovalDecision.php',
            'app/Modules/Kol/Application/Approval/KolApprovalPolicyEvaluator.php',
            'app/Modules/Kol/Application/Approval/KolApprovalService.php',
            'app/Modules/Kol/Application/Approval/ManagerApprovalCenterService.php',
            'app/Modules/Kol/Application/Contracts/AffiliateWpGateway.php',
            'app/Modules/Kol/Application/Contracts/AffiliateCommissionEvidenceReader.php',
            'app/Modules/Kol/Application/KolOperationalDashboardService.php',
            'app/Modules/Kol/Application/ProductKolProjection.php',
            'app/Modules/Kol/Application/KolScope.php',
            'app/Modules/Kol/Application/KolService.php',
            'app/Modules/Kol/Application/SavedViewService.php',
            'app/Modules/Logistics/Application/CustomerShipmentProjection.php',
            'app/Modules/Logistics/Application/Contracts/CarrierBillingEvidenceReader.php',
            'app/Modules/Logistics/Application/ProductShipmentProjection.php',
            'app/Modules/Logistics/Application/ShipmentService.php',
            'app/Modules/Logistics/Application/WarehouseRecommendationService.php',
            'app/Modules/Marketing/Application/Contracts/MarketingSubscriberSyncWriter.php',
            'app/Modules/Marketing/Application/Contracts/WordPressSubscriberGateway.php',
            'app/Modules/Marketing/Application/WordPressSubscriberSyncService.php',
            'app/Modules/Orders/Application/Contracts/WooCommerceOrderGateway.php',
            'app/Modules/Orders/Application/CustomerOrderProjection.php',
            'app/Modules/Orders/Application/ProductOrderProjection.php',
            'app/Modules/Orders/Application/Inbound/NormalizedInboundOrder.php',
            'app/Modules/Orders/Application/Inbound/WooCommerceInboundService.php',
            'app/Modules/Orders/Application/Inbound/WooCommerceOrderNormalizer.php',
            'app/Modules/Orders/Application/OrderIdentityService.php',
            'app/Modules/Orders/Application/OrderKind.php',
            'app/Modules/Orders/Application/OrderKindBackfill.php',
            'app/Modules/Orders/Application/OrderService.php',
            'app/Modules/Orders/Application/OrderSourceType.php',
            'app/Modules/Orders/Application/OrderStatus.php',
            'app/Modules/Products/Application/Contracts/ProductExternalReferenceRepository.php',
            'app/Modules/Products/Application/Contracts/ProductCostEvidenceReader.php',
            'app/Modules/Products/Application/Contracts/ProductRepository.php',
            'app/Modules/Products/Application/Contracts/SkuAliasRepository.php',
            'app/Modules/Products/Application/Contracts/SkuReconciliationRepository.php',
            'app/Modules/Products/Application/Contracts/WeightEvidenceRepository.php',
            'app/Modules/Products/Application/Contracts/WooCommerceProductGateway.php',
            'app/Modules/Products/Application/Contracts/WooCommerceProductInboundStore.php',
            'app/Modules/Products/Application/ProductImportResult.php',
            'app/Modules/Products/Application/ProductService.php',
            'app/Modules/Products/Application/ProductWeightEvidenceService.php',
            'app/Modules/Products/Application/SkuAliasService.php',
            'app/Modules/Products/Application/SkuIdentityResolver.php',
            'app/Modules/Products/Application/SkuMatchKey.php',
            'app/Modules/Products/Application/SkuReconciliationService.php',
            'app/Modules/Products/Application/SkuResolveResult.php',
            'app/Modules/Products/Application/WooCommerceProductInboundService.php',
            'app/Modules/Runtime/Application/Contracts/RedisRuntimeProbe.php',
            'app/Modules/Runtime/Application/QueueJobConvention.php',
            'app/Modules/Runtime/Application/QueueName.php',
            'app/Modules/Runtime/Application/RedisClientCapability.php',
            'app/Modules/Runtime/Application/RuntimeHealthService.php',
            'app/Modules/Shell/Application/ErpShellNavigation.php',
            'app/Modules/Shell/Application/LegacyErpNavigationPaths.php',
            'app/Modules/Shell/Application/UserUiPreferenceService.php',
            'app/Modules/Support/Application/CustomerSupportProjection.php',
            'app/Modules/Support/Application/SupportTicketService.php',
            'app/Modules/Sync/Application/Contracts/SyncOrchestrationRepository.php',
            'app/Modules/Sync/Application/Contracts/SyncOutboxRepository.php',
            'app/Modules/Sync/Application/Contracts/SyncRunRepository.php',
            'app/Modules/Sync/Application/Contracts/SyncRunTracker.php',
            'app/Modules/Sync/Application/Contracts/WooCommerceTrackingGateway.php',
            'app/Modules/Sync/Application/ManualSyncService.php',
            'app/Modules/Sync/Application/ManualSyncStatus.php',
            'app/Modules/Sync/Application/SyncOrchestrationService.php',
            'app/Modules/Sync/Application/SyncOutboxService.php',
            'app/Modules/Sync/Application/SyncOutboxStatus.php',
            'app/Modules/Sync/Application/SyncOutboxWriteException.php',
            'app/Modules/Sync/Application/SyncRunItemStatus.php',
            'app/Modules/Sync/Application/SyncRunService.php',
            'app/Modules/Sync/Application/SyncRunStatus.php',
            'app/Modules/Sync/Application/WooCommerceTrackingConflictException.php',
            'app/Modules/Sync/Application/WooCommerceTrackingOutboundService.php',
            'app/Modules/Traffic/Application/Contracts/GoogleAdsReadClient.php',
            'app/Modules/Traffic/Application/Contracts/TrafficRepository.php',
            'app/Modules/Traffic/Application/Exceptions/GoogleAdsReadException.php',
            'app/Modules/Traffic/Application/GoogleAdsTrafficSyncService.php',
            'app/Modules/Traffic/Application/TrafficService.php',
        ]), $applicationFiles);
        self::assertSame($this->sorted([...$domainFiles, ...$applicationFiles]), $this->sorted($infrastructureDependencyFiles));
        self::assertNotContains('app/Modules/Future/Providers/Provider.php', $domainFiles);
        self::assertNotContains('app/Modules/Future/Providers/Provider.php', $applicationFiles);
        self::assertSame([], $this->tokenizerViolations('DOM-001', $domainFiles, $this->domForbidden()));
        self::assertSame([], $this->tokenizerViolations('APP-001', $applicationFiles, $this->appForbidden()));
        self::assertSame([], $this->tokenizerViolations('INF-001', $infrastructureDependencyFiles, ['Infrastructure']));
    }

    public function test_domain_application_and_infrastructure_rules_have_allowed_and_violating_fixtures(): void
    {
        $domainAllowed = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse App\\Modules\\Orders\\Domain\\Order;\nfinal class Policy {}\n";
        self::assertSame([], $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Policy.php', $domainAllowed, $this->domForbidden()));
        $domainBad = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse Illuminate\\Support\\Facades\\Route;\nuse App\\Modules\\Logistics\\Infrastructure\\Carrier;\n#[App\\Modules\\Orders\\Providers\\Marker]\nfinal class Policy {}\n";
        $domainViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Policy.php', $domainBad, $this->domForbidden());
        self::assertSame(['DOM-001', 'DOM-001', 'DOM-001'], array_column($domainViolations, 'RULE_ID'));
        self::assertSame([3, 4, 5], array_column($domainViolations, 'LINE'));
        self::assertSame(['Illuminate\\Support\\Facades\\Route', 'App\\Modules\\Logistics\\Infrastructure\\Carrier', 'App\\Modules\\Orders\\Providers\\Marker'], array_column($domainViolations, 'SYMBOL_OR_DEPENDENCY'));

        $appAllowed = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Application\\Contracts\\OrderReader;\nuse App\\Modules\\Orders\\Application\\Actions\\PlaceOrder;\nfinal class Handler {}\n";
        self::assertSame([], $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $appAllowed, $this->appForbidden()));
        $appBad = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Infrastructure\\SqlOrderReader;\nuse App\\Modules\\Logistics\\Application\\Internal\\CarrierLookup;\nfinal class Handler {}\n";
        $appViolations = $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $appBad, $this->appForbidden());
        self::assertSame([3, 4], array_column($appViolations, 'LINE'));
        self::assertSame(['App\\Modules\\Orders\\Infrastructure\\SqlOrderReader', 'App\\Modules\\Logistics\\Application\\Internal\\CarrierLookup'], array_column($appViolations, 'SYMBOL_OR_DEPENDENCY'));

        $domainInfraBad = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse App\\Modules\\Orders\\Infrastructure\\EloquentOrder;\nfinal class Order {}\n";
        $domainInfraViolations = $this->sourceViolations('INF-001', 'app/Modules/Orders/Domain/Order.php', $domainInfraBad, ['Infrastructure']);
        self::assertSame(['INF-001'], array_column($domainInfraViolations, 'RULE_ID'));
        $appInfraBad = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Orders\\Infrastructure\\SqlOrderRepository;\nfinal class Handler {}\n";
        $appInfraViolations = $this->sourceViolations('INF-001', 'app/Modules/Orders/Application/Handler.php', $appInfraBad, ['Infrastructure']);
        self::assertSame(['INF-001'], array_column($appInfraViolations, 'RULE_ID'));
        $infraAllowed = "<?php\nnamespace App\\Modules\\Orders\\Infrastructure;\nuse App\\Modules\\Orders\\Domain\\Order;\nuse App\\Modules\\Orders\\Application\\Contracts\\OrderRepository;\nfinal class EloquentOrderRepository implements OrderRepository {}\n";
        self::assertSame([], $this->sourceViolations('INF-001', 'app/Modules/Orders/Infrastructure/EloquentOrderRepository.php', $infraAllowed, ['Infrastructure']));
    }

    public function test_cross_module_rules_are_derived_from_the_current_module_name(): void
    {
        $ordersDomainBilling = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nuse App\\Modules\\Billing\\Domain\\Invoice;\nfinal class Order {}\n";
        $domainViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $ordersDomainBilling, $this->domForbidden());
        self::assertSame(['App\\Modules\\Billing\\Domain\\Invoice'], array_column($domainViolations, 'SYMBOL_OR_DEPENDENCY'));

        $ordersApplicationBillingInternal = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Billing\\Application\\Internal\\BillingHandler;\nfinal class Handler {}\n";
        $internalViolations = $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $ordersApplicationBillingInternal, $this->appForbidden());
        self::assertSame(['App\\Modules\\Billing\\Application\\Internal\\BillingHandler'], array_column($internalViolations, 'SYMBOL_OR_DEPENDENCY'));

        $ordersApplicationBillingContract = "<?php\nnamespace App\\Modules\\Orders\\Application;\nuse App\\Modules\\Billing\\Application\\Contracts\\InvoiceReader;\nfinal class Handler {}\n";
        self::assertSame([], $this->sourceViolations('APP-001', 'app/Modules/Orders/Application/Handler.php', $ordersApplicationBillingContract, $this->appForbidden()));
    }

    public function test_module_namespace_must_match_its_file_path(): void
    {
        $matching = "<?php\nnamespace App\\Modules\\Orders\\Domain;\nfinal class Order {}\n";
        self::assertSame([], $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $matching, $this->domForbidden()));

        $nested = "<?php\nnamespace App\\Modules\\Orders\\Domain\\ValueObjects;\nfinal class Money {}\n";
        self::assertSame([], $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/ValueObjects/Money.php', $nested, $this->domForbidden()));

        $wrongModule = "<?php\nnamespace App\\Modules\\Billing\\Domain;\nfinal class Order {}\n";
        $wrongModuleViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $wrongModule, $this->domForbidden());
        self::assertSame(['DOM-001'], array_column($wrongModuleViolations, 'RULE_ID'));
        self::assertSame('App\\Modules\\Billing\\Domain', $wrongModuleViolations[0]['SYMBOL_OR_DEPENDENCY']);

        $wrongLayer = "<?php\nnamespace App\\Modules\\Orders\\Infrastructure;\nfinal class Order {}\n";
        $wrongLayerViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $wrongLayer, $this->domForbidden());
        self::assertSame(['DOM-001'], array_column($wrongLayerViolations, 'RULE_ID'));
        self::assertSame('App\\Modules\\Orders\\Infrastructure', $wrongLayerViolations[0]['SYMBOL_OR_DEPENDENCY']);
        self::assertSame(['RULE_ID', 'FILE', 'LINE', 'SYMBOL_OR_DEPENDENCY', 'REASON'], array_keys($wrongLayerViolations[0]));

        $missing = "<?php\nfinal class Order {}\n";
        $missingViolations = $this->sourceViolations('DOM-001', 'app/Modules/Orders/Domain/Order.php', $missing, $this->domForbidden());
        self::assertSame(['DOM-001'], array_column($missingViolations, 'RULE_ID'));
        self::assertSame(1, $missingViolations[0]['LINE']);
        self::assertSame('MISSING_NAMESPACE', $missingViolations[0]['SYMBOL_OR_DEPENDENCY']);
        self::assertSame('module file must declare a namespace matching its module layer path', $missingViolations[0]['REASON']);
    }

    public function test_provider_rule_collects_real_sources_and_rejects_forbidden_fixture(): void
    {
        $violations = $this->providerViolations();
        self::assertSame([], $violations, $this->formatRecords($violations));
        $fixture = <<<'PHP'
<?php
namespace Fixture;
use Illuminate\Support\Facades\Route;
final class Provider { public function register() { glob('app/*'); Route::get('/x'); $provider = $this->app->make($name); } }
PHP;
        $fixtureViolations = $this->sourceViolations('PROV-001', 'app/Modules/Future/Providers/BadProvider.php', $fixture, $this->providerForbidden());
        self::assertNotEmpty($fixtureViolations);
        self::assertSame(['RULE_ID', 'FILE', 'LINE', 'SYMBOL_OR_DEPENDENCY', 'REASON'], array_keys($fixtureViolations[0]));
        self::assertStringContainsString('PROV-001', $this->formatRecords($fixtureViolations));
    }

    public function test_tokenizer_handles_namespaces_uses_fqns_attributes_and_ignores_text(): void
    {
        $fixture = <<<'PHP'
<?php
namespace Fixture\Domain;
use Vendor\Package\{Allowed, Nested\Dependency as Alias};
use \Fully\Qualified\Dependency;
#[Qualified\AttributeDependency]
final class Fixture
{
    // Route::fake()
    /** Illuminate\Support\Facades\Route */
    private string $value = 'Route::fake()';
    private string $heredoc = <<<TXT
Route::fake()
TXT;
}
PHP;
        self::assertSame([], $this->sourceViolations('DOM-001', 'memory-fixture.php', $fixture, ['Route', 'Illuminate\\']));
        $groupViolation = $this->sourceViolations('DOM-001', 'memory-fixture.php', "<?php\nuse Illuminate\\Support\\{Facades\\Route, Http\\Request};", ['Illuminate\\']);
        self::assertSame(['Illuminate\\Support\\Facades\\Route', 'Illuminate\\Support\\Http\\Request'], array_column($groupViolation, 'SYMBOL_OR_DEPENDENCY'));
        $violation = $this->sourceViolations('DOM-001', 'memory-fixture.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;", ['Illuminate\\']);
        self::assertCount(1, $violation);
        self::assertSame(2, $violation[0]['LINE']);
        self::assertSame('Illuminate\\Support\\Facades\\Route', $violation[0]['SYMBOL_OR_DEPENDENCY']);
    }

    public function test_all_rule_ids_have_real_assertions_and_compatibility_evidence(): void
    {
        $ruleIds = ['MOD-001', 'DOM-001', 'APP-001', 'INF-001', 'PROV-001', 'SHARED-001', 'SUPPORT-001', 'MIG-001', 'COMPAT-001', 'FE-001'];
        self::assertSame($ruleIds, array_keys(array_flip($ruleIds)));
        $this->assertFixedFileSet(array_keys(self::MIGRATIONS), 'MIG-001', 'database/migrations');
        foreach (self::MIGRATIONS as $path => $hash) {
            self::assertSame($hash, hash_file('sha256', $this->path($path)));
        }
        foreach (self::FRONTEND_FILES as $path => $hash) {
            self::assertFileExists($this->path($path));
            self::assertSame($hash, hash_file('sha256', $this->path($path)), 'FE-001 '.$path);
        }
        self::assertSame(array_keys(self::FRONTEND_FILES), $this->allFilesUnder($this->path('resources/js')), 'FE-001 frontend file set changed');
        self::assertSame('c77096f7fa4e11fadae2e9549032443658467ca17411521159dd6736d0d1887f', hash_file('sha256', $this->path('menu/APPROVED_MENU.json')));
        self::assertSame($this->sorted(self::ROUTE_NAMES), $this->routeNames(), 'COMPAT-001 route names changed');
        $permissionKeys = array_values(array_filter(array_map(fn ($key): ?string => str_contains($key->value, '.') ? $key->value : null, AuthorizationKeys::cases())));
        sort($permissionKeys);
        self::assertSame(self::PERMISSION_KEYS, $permissionKeys, 'COMPAT-001 permission keys changed');
        $appProvider = (string) file_get_contents($this->path('app/Providers/AppServiceProvider.php'));
        $auditProvider = (string) file_get_contents($this->path('app/Modules/Audit/Providers/AuditServiceProvider.php'));
        self::assertStringContainsString('AuditWriter::class', $auditProvider);
        $identityFortify = (string) file_get_contents($this->path('app/Modules/Identity/Experience/Fortify/IdentityFortify.php'));
        foreach (['Gate::before', 'Gate::define', 'AuthorizationKeys::cases'] as $evidence) {
            self::assertStringContainsString($evidence, $identityFortify);
        }
        self::assertSame(ServiceProvider::class, get_parent_class(ModulesServiceProvider::class));
        self::assertSame([
            IdentityServiceProvider::class,
            KolServiceProvider::class,
            OrdersServiceProvider::class,
            LogisticsServiceProvider::class,
            FinanceServiceProvider::class,
            GuidesServiceProvider::class,
            RuntimeServiceProvider::class,
            AuditServiceProvider::class,
            SyncServiceProvider::class,
            ManualSyncServiceProvider::class,
            ShellServiceProvider::class,
            DashboardServiceProvider::class,
            CustomersServiceProvider::class,
            ProductsServiceProvider::class,
            InventoryServiceProvider::class,
            MarketingServiceProvider::class,
            SupportServiceProvider::class,
            BackupServiceProvider::class,
            BusinessProjectsServiceProvider::class,
            TrafficServiceProvider::class,
        ], ModuleManifest::providers());
        self::assertSame([AppServiceProvider::class, ModulesServiceProvider::class], require $this->path('bootstrap/providers.php'));
    }

    /** @return list<string> */
    private function moduleLayerFiles(string $layer): array
    {
        $files = [];
        foreach (glob($this->path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $module) {
            $directory = $module.'/'.$layer;
            if (! is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace($this->path().'/', '', $file->getPathname());
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @param list<string> $files @param list<string> $forbidden */
    private function tokenizerViolations(string $ruleId, array $files, array $forbidden): array
    {
        $records = [];
        foreach ($files as $file) {
            $records = [...$records, ...$this->sourceViolations($ruleId, $file, (string) file_get_contents($this->path($file)), $forbidden)];
        }

        return $records;
    }

    /** @param list<string> $forbidden @return list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> */
    private function sourceViolations(string $ruleId, string $file, string $source, array $forbidden): array
    {
        $records = $this->namespaceViolations($ruleId, $file, $source);
        $forbidden = [...$forbidden, ...$this->moduleBoundaryForbidden($ruleId, $file)];
        $tokens = token_get_all($source);
        $usePrefix = '';
        $inUse = false;
        $inNamespace = false;
        $braceDepth = 0;
        foreach ($tokens as $tokenIndex => $token) {
            if (! is_array($token)) {
                if ($inNamespace && $token === ';') {
                    $inNamespace = false;
                }
                if ($inUse && $token === '{') {
                    $braceDepth = 1;
                } elseif ($inUse && $token === '}' && $braceDepth > 0) {
                    $braceDepth--;
                } elseif ($inUse && $token === ';') {
                    $inUse = false;
                    $usePrefix = '';
                }

                continue;
            }
            [$type, $text, $line] = $token;
            if (in_array($type, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if ($type === T_NAMESPACE) {
                $inNamespace = true;

                continue;
            }
            if ($inNamespace) {
                continue;
            }
            if ($type === T_USE) {
                $inUse = true;
                $braceDepth = 0;
            }
            $symbol = null;
            if ($type === T_VARIABLE && $text === '$name') {
                $symbol = $text;
            } elseif (in_array($type, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true) || (defined('T_NAME_RELATIVE') && $type === T_NAME_RELATIVE)) {
                $symbol = trim($text, "\\ \t\r\n");
                if ($inUse && $braceDepth > 0 && $usePrefix !== '' && $type !== T_STRING) {
                    $symbol = trim($usePrefix, '\\').'\\'.$symbol;
                }
                if ($inUse && $braceDepth === 0 && $type !== T_STRING) {
                    $usePrefix = trim($text, "\\ \t\r\n");
                }
            }
            if ($symbol === null) {
                continue;
            }
            if ($inUse && $braceDepth === 0 && $type !== T_STRING) {
                $nextIndex = $tokenIndex + 1;
                while (isset($tokens[$nextIndex]) && is_array($tokens[$nextIndex]) && $tokens[$nextIndex][0] === T_WHITESPACE) {
                    $nextIndex++;
                }
                $nextToken = $tokens[$nextIndex] ?? null;
                if ($nextToken === '{' || (is_array($nextToken) && $nextToken[0] === T_NS_SEPARATOR)) {
                    continue;
                }
            }
            foreach ($forbidden as $dependency) {
                $matches = $dependency === 'App\\Modules\\'
                    ? $this->matchesModuleBoundary($ruleId, $file, $symbol)
                    : $this->matchesDependency($symbol, $dependency);
                if ($matches) {
                    $records[] = ['RULE_ID' => $ruleId, 'FILE' => $file, 'LINE' => $line, 'SYMBOL_OR_DEPENDENCY' => $symbol, 'REASON' => 'forbidden namespace/use/FQN/group-use/attribute dependency'];
                    break;
                }
            }
        }

        return $records;
    }

    private function matchesDependency(string $symbol, string $dependency): bool
    {
        return $symbol === trim($dependency, '\\') || str_starts_with($symbol, trim($dependency, '\\').'\\') || str_contains($symbol, $dependency);
    }

    private function matchesModuleBoundary(string $ruleId, string $file, string $symbol): bool
    {
        if (! preg_match('#^app/Modules/([^/]+)/(Domain|Application)/#', $file, $fileMatches)) {
            return false;
        }
        if (! preg_match('#^App\\\\Modules\\\\([^\\\\]+)\\\\(.+)$#', $symbol, $symbolMatches)) {
            return false;
        }
        if ($symbolMatches[1] === $fileMatches[1]) {
            return false;
        }

        return $ruleId === 'DOM-001'
            || ($ruleId === 'APP-001' && str_starts_with($symbolMatches[2], 'Application\\Internal\\'));
    }

    /** @return list<string> */
    private function moduleBoundaryForbidden(string $ruleId, string $file): array
    {
        if (! preg_match('#^app/Modules/([^/]+)/(Domain|Application)/#', $file, $matches)) {
            return [];
        }
        $module = $matches[1];
        if ($ruleId === 'DOM-001') {
            return ['App\\Modules\\'];
        }
        if ($ruleId === 'APP-001') {
            return ['App\\Modules\\'];
        }

        return [];
    }

    /** @return list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> */
    private function namespaceViolations(string $ruleId, string $file, string $source): array
    {
        if (! preg_match('#^app/Modules/([^/]+)/(Domain|Application|Infrastructure|Providers)/#', $file, $matches)) {
            return [];
        }
        $expected = 'App\\Modules\\'.$matches[1].'\\'.$matches[2];
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }
            $namespace = '';
            $line = $token[2];
            for ($cursor = $index + 1; isset($tokens[$cursor]); $cursor++) {
                $part = $tokens[$cursor];
                if ($part === ';' || $part === '{') {
                    break;
                }
                if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $namespace .= trim($part[1], '\\');
                } elseif ($part === '\\') {
                    $namespace .= '\\';
                }
            }
            if ($namespace !== $expected && ! str_starts_with($namespace, $expected.'\\')) {
                return [[
                    'RULE_ID' => $ruleId,
                    'FILE' => $file,
                    'LINE' => $line,
                    'SYMBOL_OR_DEPENDENCY' => $namespace,
                    'REASON' => 'declared namespace does not match module file path',
                ]];
            }

            return [];
        }

        return [[
            'RULE_ID' => $ruleId,
            'FILE' => $file,
            'LINE' => 1,
            'SYMBOL_OR_DEPENDENCY' => 'MISSING_NAMESPACE',
            'REASON' => 'module file must declare a namespace matching its module layer path',
        ]];
    }

    /** @return list<string> */
    private function domForbidden(): array
    {
        return ['Illuminate\\', 'Facade', 'Controller', 'Request', 'Route', 'Inertia', 'Eloquent', 'Infrastructure', 'Experience', 'Providers'];
    }

    /** @return list<string> */
    private function appForbidden(): array
    {
        return ['Infrastructure'];
    }

    /** @return list<string> */
    private function providerForbidden(): array
    {
        return ['filesystem', 'glob', 'scandir', 'Finder', 'convention', 'Route::', 'Migration', 'View', 'Event', 'Queue', 'Schedule', 'Redis', '$name'];
    }

    /** @return list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> */
    private function providerViolations(): array
    {
        $files = ['app/Modules/ModuleManifest.php', 'app/Modules/ModulesServiceProvider.php'];
        foreach (glob($this->path('app/Modules/*/Providers'), GLOB_ONLYDIR) ?: [] as $directory) {
            $files = [...$files, ...$this->filesUnder($directory)];
        }

        return $this->tokenizerViolations('PROV-001', $files, $this->providerForbidden());
    }

    /** @param list<string> $expected */
    private function assertFixedFileSet(array $expected, string $ruleId, string $directory): void
    {
        foreach ($expected as $path) {
            self::assertFileExists($this->path($path));
        }
        self::assertSame($expected, $this->filesUnder($this->path($directory)), $ruleId.' baseline file set changed');
    }

    /** @return list<string> */
    private function filesUnder(string $directory): array
    {
        $files = [];
        if (is_file($directory)) {
            return [str_replace($this->path().'/', '', $directory)];
        }
        if (! is_dir($directory)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace($this->path().'/', '', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function allFilesUnder(string $directory): array
    {
        if (is_file($directory)) {
            return [str_replace($this->path().'/', '', $directory)];
        }
        if (! is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace($this->path().'/', '', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function subdirectoriesUnder(string $directory): array
    {
        $directories = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $directories[] = str_replace($this->path().'/', '', $file->getPathname());
            }
        }
        sort($directories);

        return $directories;
    }

    /** @param list<array{RULE_ID:string,FILE:string,LINE:int,SYMBOL_OR_DEPENDENCY:string,REASON:string}> $records */
    private function formatRecords(array $records): string
    {
        return json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function path(string $relative = ''): string
    {
        return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /** @return list<string> */
    private function routeNames(): array
    {
        $application = require $this->path('bootstrap/app.php');
        $application->make(Kernel::class)->bootstrap();
        $names = array_values(array_filter(array_map(
            fn (Route $route): ?string => $route->getName(),
            $application->make('router')->getRoutes()->getRoutes(),
        ), fn (?string $name): bool => $name !== null));
        sort($names);

        return $names;
    }
}

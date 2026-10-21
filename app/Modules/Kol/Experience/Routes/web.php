<?php

use App\Modules\Kol\Experience\Controllers\AffiliateWpSyncController;
use App\Modules\Kol\Experience\Controllers\KolApprovalCenterController;
use App\Modules\Kol\Experience\Controllers\KolCollaborationController;
use App\Modules\Kol\Experience\Controllers\KolCommunicationController;
use App\Modules\Kol\Experience\Controllers\KolContentController;
use App\Modules\Kol\Experience\Controllers\KolController;
use App\Modules\Kol\Experience\Controllers\KolImportPreviewController;
use App\Modules\Kol\Experience\Controllers\KolImportActivationController;
use App\Modules\Kol\Experience\Controllers\KolAffiliateBindingActivationController;
use App\Modules\Kol\Experience\Controllers\KolOpportunityController;
use App\Modules\Kol\Experience\Controllers\KolOwnerMappingController;
use App\Modules\Kol\Experience\Controllers\KolOverviewController;
use App\Modules\Kol\Experience\Controllers\ReferralCommissionController;
use App\Modules\Kol\Experience\Controllers\PayoutWorkflowController;
use App\Modules\Kol\Experience\Controllers\SavedViewController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web', 'auth', 'can:kol.view'])->group(function (): void {
    Route::get('/kol', KolOverviewController::class)->name('shell.kol');
    Route::get('/kol/overview', fn () => redirect()->route('shell.kol'))->name('kol.overview');

    Route::get('/kol/library', [KolController::class, 'index'])->name('kol.library');
    Route::get('/kol/library/create', [KolController::class, 'create'])->middleware('can:kol.operate')->name('kol.library.create');
    Route::post('/kol/library', [KolController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.store');
    Route::get('/kol/library/{kol}', [KolController::class, 'show'])->name('kol.library.show');
    Route::get('/kol/library/{kol}/edit', [KolController::class, 'edit'])->middleware('can:kol.operate')->name('kol.library.edit');
    Route::patch('/kol/library/{kol}', [KolController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.update');
    Route::post('/kol/library/{kol}/affiliate-account', [KolController::class, 'provisionAffiliateAccount'])->middleware('can:kol.operate')->name('kol.library.affiliate-account.store');
    Route::post('/kol/library/{kol}/wordpress-password', [KolController::class, 'updateWordpressPassword'])->middleware('can:kol.operate')->name('kol.library.wordpress-password.update');
    Route::post('/kol/library/{kol}/avatar', [KolController::class, 'uploadAvatar'])->middleware('can:kol.operate')->name('kol.library.avatar');
    Route::post('/kol/library/{kol}/opportunities', [KolOpportunityController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.opportunities.store');
    Route::patch('/kol/library/{kol}/opportunities/{opportunity}', [KolOpportunityController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.opportunities.update');
    Route::post('/kol/library/{kol}/communications', [KolCommunicationController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.communications.store');
    Route::patch('/kol/library/{kol}/communications/{communication}', [KolCommunicationController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.communications.update');
    Route::post('/kol/library/{kol}/collaborations', [KolCollaborationController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.collaborations.store');
    Route::patch('/kol/library/{kol}/collaborations/{collaboration}', [KolCollaborationController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.collaborations.update');
    Route::post('/kol/library/{kol}/collaborations/{collaboration}/fulfillment-order', [KolCollaborationController::class, 'createFulfillmentOrder'])->middleware(['can:kol.operate', 'can:orders.create'])->name('kol.library.collaborations.fulfillment-order');
    Route::post('/kol/library/{kol}/opportunities/{opportunity}/convert-to-collaboration', [KolCollaborationController::class, 'convertOpportunity'])->middleware('can:kol.operate')->name('kol.library.opportunities.convert-collaboration');
    Route::post('/kol/library/{kol}/archive', [KolController::class, 'archive'])->middleware('can:kol.operate')->name('kol.library.archive');
    Route::post('/kol/library/{kol}/reactivate', [KolController::class, 'reactivate'])->middleware('can:kol.operate')->name('kol.library.reactivate');

    Route::middleware('can:kol.views.manage')->group(function (): void {
        Route::post('/kol/views', [SavedViewController::class, 'store'])->name('kol.views.store');
        Route::patch('/kol/views/{savedView}', [SavedViewController::class, 'update'])->name('kol.views.update');
        Route::delete('/kol/views/{savedView}', [SavedViewController::class, 'destroy'])->name('kol.views.destroy');
    });

    Route::middleware('can:kol.approvals.manage')->group(function (): void {
        Route::get('/kol/approvals', [KolApprovalCenterController::class, 'index'])->name('kol.approvals');
        Route::get('/kol/approvals/kols', [KolApprovalCenterController::class, 'kols'])->name('kol.approvals.kols');
        Route::get('/kol/approvals/collaborations', [KolApprovalCenterController::class, 'collaborations'])->name('kol.approvals.collaborations');
        Route::get('/kol/approvals/payouts', [KolApprovalCenterController::class, 'payouts'])->name('kol.approvals.payouts');
        Route::post('/kol/approvals/kols/{kol}/approve', [KolApprovalCenterController::class, 'approveKol'])->name('kol.approvals.kols.approve');
        Route::post('/kol/approvals/kols/{kol}/reject', [KolApprovalCenterController::class, 'rejectKol'])->name('kol.approvals.kols.reject');
        Route::post('/kol/approvals/collaborations/{collaboration}/approve', [KolApprovalCenterController::class, 'approveCollaboration'])->name('kol.approvals.collaborations.approve');
        Route::post('/kol/approvals/collaborations/{collaboration}/reject', [KolApprovalCenterController::class, 'rejectCollaboration'])->name('kol.approvals.collaborations.reject');
        Route::post('/kol/approvals/payouts/{payoutRequest}/approve', [KolApprovalCenterController::class, 'approvePayout'])->name('kol.approvals.payouts.approve');
        Route::post('/kol/approvals/payouts/{payoutRequest}/reject', [KolApprovalCenterController::class, 'rejectPayout'])->name('kol.approvals.payouts.reject');
    });

    // Placeholder routes for M1.1+ menu items — real at nav level, no business engine yet
    Route::get('/kol/opportunities', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '合作机会', 'note' => 'M1.1 CRM 包交付']))->name('kol.opportunities');
    Route::get('/kol/collaborations', [KolCollaborationController::class, 'index'])->name('kol.collaborations');
    Route::get('/kol/communications', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '沟通跟进', 'note' => 'M1.1 CRM 包交付']))->name('kol.communications');
    Route::get('/kol/samples', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '样品与发货', 'note' => 'M1.2 合作包交付']))->name('kol.samples');
    Route::get('/kol/content', [KolContentController::class, 'index'])->name('kol.content');
    Route::get('/kol/content/options', [KolContentController::class, 'options'])->name('kol.content.options');
    Route::post('/kol/contents', [KolContentController::class, 'store'])->middleware('can:kol.operate')->name('kol.contents.store');
    Route::patch('/kol/contents/{content}', [KolContentController::class, 'update'])->middleware('can:kol.operate')->name('kol.contents.update');
    Route::post('/kol/contents/{content}/evidence', [KolContentController::class, 'addEvidence'])->middleware('can:kol.operate')->name('kol.contents.evidence.store');
    Route::post('/kol/contents/{content}/sync-post-image', [KolContentController::class, 'syncPostImage'])->middleware('can:kol.operate')->name('kol.contents.sync-post-image');
    Route::get('/kol/contents/{content}/evidence/{attachment}', [KolContentController::class, 'showEvidence'])->name('kol.contents.evidence.show');
    Route::delete('/kol/contents/{content}/evidence/{attachment}', [KolContentController::class, 'deleteEvidence'])->middleware('can:kol.operate')->name('kol.contents.evidence.destroy');
    Route::get('/kol/commissions', [ReferralCommissionController::class, 'index'])->name('kol.commissions');
    Route::post('/kol/commissions/sync', [ReferralCommissionController::class, 'sync'])
        ->middleware('can:kol.affiliates.manage')
        ->name('kol.commissions.sync');
    Route::get('/kol/affiliatewp', [AffiliateWpSyncController::class, 'index'])->name('kol.affiliatewp');
    Route::get('/kol/affiliatewp/reconciliation', [AffiliateWpSyncController::class, 'reconciliation'])
        ->middleware('can:kol.affiliates.manage')->name('kol.affiliatewp.reconciliation');
    Route::post('/kol/affiliatewp/reconciliation/preview', [AffiliateWpSyncController::class, 'previewReconciliation'])
        ->middleware('can:kol.affiliates.manage')->name('kol.affiliatewp.reconciliation.preview');
    Route::get('/kol/import-preview', [KolImportPreviewController::class, 'index'])
        ->middleware(['can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.import-preview');
    Route::post('/kol/import-preview/generate', [KolImportPreviewController::class, 'generate'])
        ->middleware(['can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.import-preview.generate');
    Route::get('/kol/owner-mapping', [KolOwnerMappingController::class, 'index'])
        ->middleware(['can:kol.approvals.manage', 'can:kol.affiliates.manage'])->name('kol.owner-mapping');
    Route::post('/kol/owner-mapping/source', [KolOwnerMappingController::class, 'storeSource'])
        ->middleware(['can:kol.approvals.manage', 'can:kol.affiliates.manage'])->name('kol.owner-mapping.source.store');
    Route::delete('/kol/owner-mapping/source/{mapping}', [KolOwnerMappingController::class, 'destroySource'])
        ->middleware(['can:kol.approvals.manage', 'can:kol.affiliates.manage'])->name('kol.owner-mapping.source.destroy');
    Route::post('/kol/owner-mapping/decision', [KolOwnerMappingController::class, 'storeDecision'])
        ->middleware(['can:kol.approvals.manage', 'can:kol.affiliates.manage'])->name('kol.owner-mapping.decision.store');
    Route::delete('/kol/owner-mapping/decision/{decision}', [KolOwnerMappingController::class, 'destroyDecision'])
        ->middleware(['can:kol.approvals.manage', 'can:kol.affiliates.manage'])->name('kol.owner-mapping.decision.destroy');
    Route::get('/kol/import-activation', [KolImportActivationController::class, 'index'])
        ->middleware(['can:system.permissions.manage', 'can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.import-activation');
    Route::post('/kol/import-activation/prepare', [KolImportActivationController::class, 'prepare'])
        ->middleware(['can:system.permissions.manage', 'can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.import-activation.prepare');
    Route::post('/kol/import-activation/{activationBatch}/apply', [KolImportActivationController::class, 'apply'])
        ->middleware(['can:system.permissions.manage', 'can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.import-activation.apply');
    Route::get('/kol/affiliatewp/binding-activation', [KolAffiliateBindingActivationController::class, 'index'])
        ->middleware(['can:system.permissions.manage', 'can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.affiliatewp.binding-activation');
    Route::post('/kol/affiliatewp/binding-activation/prepare', [KolAffiliateBindingActivationController::class, 'prepare'])
        ->middleware(['can:system.permissions.manage', 'can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.affiliatewp.binding-activation.prepare');
    Route::post('/kol/affiliatewp/binding-activation/{bindingBatch}/apply', [KolAffiliateBindingActivationController::class, 'apply'])
        ->middleware(['can:system.permissions.manage', 'can:kol.operate', 'can:kol.affiliates.manage'])->name('kol.affiliatewp.binding-activation.apply');
    Route::post('/kol/affiliatewp/sync', [AffiliateWpSyncController::class, 'sync'])
        ->middleware('can:kol.affiliates.manage')
        ->name('kol.affiliatewp.sync');
    Route::post('/kol/affiliatewp/bind', [AffiliateWpSyncController::class, 'bind'])
        ->middleware('can:kol.affiliates.manage')
        ->name('kol.affiliatewp.bind');
    Route::post('/kol/affiliatewp/convert', [AffiliateWpSyncController::class, 'convert'])
        ->middleware('can:kol.affiliates.manage')
        ->name('kol.affiliatewp.convert');
    Route::post('/kol/affiliatewp/provision', [AffiliateWpSyncController::class, 'provision'])
        ->middleware('can:kol.affiliates.manage')
        ->name('kol.affiliatewp.provision');
    Route::post('/kol/affiliatewp/coupons/sync', [AffiliateWpSyncController::class, 'syncCoupons'])
        ->middleware('can:kol.affiliates.manage')
        ->name('kol.affiliatewp.coupons.sync');
    Route::post('/kol/affiliatewp/coupons/provision', [AffiliateWpSyncController::class, 'provisionCoupon'])
        ->middleware('can:kol.affiliates.manage')
        ->name('kol.affiliatewp.coupons.provision');
    Route::get('/kol/affiliate', fn () => redirect()->route('kol.affiliatewp'))->name('kol.affiliate');
});


Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/kol/payouts', [PayoutWorkflowController::class, 'index'])->name('kol.payouts');
    Route::post('/kol/payouts/sync', [PayoutWorkflowController::class, 'sync'])->name('kol.payouts.sync');
    Route::post('/kol/payouts', [PayoutWorkflowController::class, 'store'])->name('kol.payouts.store');
    Route::post('/kol/payouts/{payoutRequest}/approve', [PayoutWorkflowController::class, 'approve'])->name('kol.payouts.approve');
    Route::post('/kol/payouts/{payoutRequest}/reject', [PayoutWorkflowController::class, 'reject'])->name('kol.payouts.reject');
    Route::post('/kol/payouts/{payoutRequest}/complete', [PayoutWorkflowController::class, 'complete'])->name('kol.payouts.complete');
});

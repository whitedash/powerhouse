<?php

use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\Internal\BillingEntityController as InternalBillingEntityController;
use App\Http\Controllers\Internal\CustomerController as InternalCustomerController;
use App\Http\Controllers\Internal\DashboardController as InternalDashboardController;
use App\Http\Controllers\Internal\DomainController as InternalDomainController;
use App\Http\Controllers\Internal\InvoiceController as InternalInvoiceController;
use App\Http\Controllers\Internal\MaavelusStatementController as InternalMaavelusStatementController;
use App\Http\Controllers\Internal\ProductController as InternalProductController;
use App\Http\Controllers\Internal\ProductOverviewController as InternalProductOverviewController;
use App\Http\Controllers\Internal\ProductPlanCategoryController as InternalProductPlanCategoryController;
use App\Http\Controllers\Internal\ProductPlanController as InternalProductPlanController;
use App\Http\Controllers\Internal\ProductPlanPriceController as InternalProductPlanPriceController;
use App\Http\Controllers\Internal\ProvisioningController as InternalProvisioningController;
use App\Http\Controllers\Internal\ReferrerController as InternalReferrerController;
use App\Http\Controllers\Internal\SettingsController as InternalSettingsController;
use App\Http\Controllers\Internal\SubscriptionController as InternalSubscriptionController;
use App\Http\Controllers\Internal\SupportController as InternalSupportController;
use App\Http\Controllers\Internal\TaskController as InternalTaskController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\ProductController as PortalProductController;
use App\Http\Controllers\Portal\SettingsController as PortalSettingsController;
use App\Http\Controllers\Portal\SupportController as PortalSupportController;
use App\Http\Controllers\Referrer\CommissionController as ReferrerCommissionController;
use App\Http\Controllers\Referrer\CustomerController as ReferrerCustomerController;
use App\Http\Controllers\Referrer\DashboardController as ReferrerDashboardController;
use App\Http\Controllers\Referrer\PayoutController as ReferrerPayoutController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Group 1 — Internal (staff)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:super_admin,staff'])->group(function () {
    Route::get('/', [InternalDashboardController::class, 'index'])->name('internal.dashboard');

    // List/search endpoints — 60/min/user to slow bulk scraping
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/customers', [InternalCustomerController::class, 'index'])->name('internal.customers.index');
        Route::get('/invoices', [InternalInvoiceController::class, 'index'])->name('internal.invoices.index');
        Route::get('/referrers', [InternalReferrerController::class, 'index'])->name('internal.referrers.index');
    });

    // Mutations live outside the throttle group — bulk approvals can
    // genuinely fire several requests close together and they're behind
    // super_admin anyway.
    Route::middleware('role:super_admin')->prefix('referrers')->name('internal.referrers.')->group(function () {
        Route::post('/', [InternalReferrerController::class, 'store'])->name('store');
        Route::post('/approve-all', [InternalReferrerController::class, 'approveAll'])->name('approve-all');
        Route::post('/{id}/approve', [InternalReferrerController::class, 'approveCommission'])->name('approve');
        Route::post('/{id}/mark-paid', [InternalReferrerController::class, 'markPaid'])->name('mark-paid');
    });

    Route::post('/customers', [InternalCustomerController::class, 'store'])->name('internal.customers.store');
    Route::get('/customers/{id}', [InternalCustomerController::class, 'show'])->name('internal.customers.show');
    Route::put('/customers/{id}', [InternalCustomerController::class, 'update'])->name('internal.customers.update');
    Route::post('/customers/{id}/notes', [InternalCustomerController::class, 'storeNote'])->name('internal.customers.notes.store');
    Route::post('/customers/{id}/tasks', [InternalCustomerController::class, 'storeTask'])->name('internal.customers.tasks.store');

    // Global task endpoints — for the dashboard New-task slide-over and
    // checkbox-completion on every list that surfaces tasks.
    Route::post('/tasks', [InternalTaskController::class, 'store'])->name('internal.tasks.store');
    Route::post('/tasks/{id}/complete', [InternalTaskController::class, 'complete'])->name('internal.tasks.complete');
    Route::delete('/customers/{id}/archive', [InternalCustomerController::class, 'archive'])->name('internal.customers.archive');

    // Product subscriptions on a customer — enabling a new product creates
    // a CustomerProduct, suspending removes their access. Both stay open
    // to staff (alongside super_admin) so account managers can wire up
    // a new sub without escalating every time.
    Route::post('/customers/{id}/products', [InternalCustomerController::class, 'enableProduct'])->name('internal.customers.products.enable');
    Route::post('/customers/{id}/products/{productId}/suspend', [InternalCustomerController::class, 'suspendProduct'])->name('internal.customers.products.suspend');

    Route::get('/invoices/new', [InternalInvoiceController::class, 'create'])->name('internal.invoices.create');
    Route::post('/invoices', [InternalInvoiceController::class, 'store'])->name('internal.invoices.store');
    Route::get('/invoices/{id}', [InternalInvoiceController::class, 'show'])->name('internal.invoices.show');
    Route::get('/invoices/{id}/edit', [InternalInvoiceController::class, 'edit'])->name('internal.invoices.edit');
    Route::put('/invoices/{id}', [InternalInvoiceController::class, 'update'])->name('internal.invoices.update');
    Route::get('/invoices/{id}/pdf', [InternalInvoiceController::class, 'downloadPdf'])->name('internal.invoices.pdf');
    Route::get('/invoices/{id}/preview-pdf', [InternalInvoiceController::class, 'previewPdf'])->name('internal.invoices.preview-pdf');
    Route::post('/invoices/{id}/mark-paid', [InternalInvoiceController::class, 'markPaid'])->name('internal.invoices.mark-paid');
    Route::post('/invoices/{id}/void', [InternalInvoiceController::class, 'voidInvoice'])->name('internal.invoices.void');
    Route::post('/invoices/{id}/send', [InternalInvoiceController::class, 'sendInvoice'])->name('internal.invoices.send');
    Route::post('/invoices/{id}/send-reminder', [InternalInvoiceController::class, 'sendReminder'])->name('internal.invoices.send-reminder');
    Route::post('/invoices/{id}/pause-reminders', [InternalInvoiceController::class, 'pauseReminders'])->name('internal.invoices.pause-reminders');
    Route::post('/invoices/{id}/resume-reminders', [InternalInvoiceController::class, 'resumeReminders'])->name('internal.invoices.resume-reminders');
    Route::get('/domains', [InternalDomainController::class, 'index'])->name('internal.domains.index');

    // Product overview pages — one per product, navigated to from
    // the sidebar Products section. Lives outside the settings group
    // because it's read-only for staff (no super_admin gate).
    Route::get('/products/{slug}', [InternalProductOverviewController::class, 'show'])->name('internal.products.show');
    Route::get('/support', [InternalSupportController::class, 'index'])->name('internal.support.index');
    Route::get('/provisioning', [InternalProvisioningController::class, 'index'])->name('internal.provisioning.index');
    Route::post('/provisioning/toggle', [InternalProvisioningController::class, 'toggle'])->name('internal.provisioning.toggle');

    Route::get('/subscriptions', [InternalSubscriptionController::class, 'index'])->name('internal.subscriptions.index');
    Route::put('/subscriptions/{id}', [InternalSubscriptionController::class, 'update'])->name('internal.subscriptions.update');
    Route::post('/subscriptions/{id}/cancel', [InternalSubscriptionController::class, 'cancel'])->name('internal.subscriptions.cancel');
    Route::get('/settings', [InternalSettingsController::class, 'index'])->name('internal.settings.index');

    // Settings sub-pages that mutate global config (billing entities, etc.)
    // are super_admin-only. Staff can read /settings overview but not the
    // sensitive editors. Nested middleware extends — not replaces — the
    // outer auth + role:super_admin,staff guard.
    Route::middleware('role:super_admin')->prefix('settings')->name('internal.settings.')->group(function () {
        // Team
        Route::get('/team', [InternalSettingsController::class, 'team'])->name('team');
        Route::post('/team/invite', [InternalSettingsController::class, 'teamInvite'])->name('team.invite');
        Route::put('/team/{id}/role', [InternalSettingsController::class, 'teamUpdateRole'])->name('team.role');
        Route::delete('/team/{id}', [InternalSettingsController::class, 'teamRemove'])->name('team.remove');

        // Security
        Route::get('/security', [InternalSettingsController::class, 'security'])->name('security');
        Route::post('/security/password', [InternalSettingsController::class, 'securityChangePassword'])->name('security.password');
        Route::post('/security/sessions/clear', [InternalSettingsController::class, 'securityClearSessions'])->name('security.sessions.clear');

        // Notifications
        Route::get('/notifications', [InternalSettingsController::class, 'notifications'])->name('notifications');
        Route::post('/notifications', [InternalSettingsController::class, 'notificationsUpdate'])->name('notifications.update');

        // Integrations
        Route::get('/integrations', [InternalSettingsController::class, 'integrations'])->name('integrations');
        Route::get('/integrations/{name}/test', [InternalSettingsController::class, 'integrationTest'])->name('integrations.test');

        // Audit log
        Route::get('/audit-log', [InternalSettingsController::class, 'auditLog'])->name('audit-log');

        // Danger zone
        Route::get('/danger', [InternalSettingsController::class, 'danger'])->name('danger');
        Route::post('/danger/reset-notifications', [InternalSettingsController::class, 'dangerResetNotifications'])->name('danger.reset-notifications');

        Route::get('/billing-entities', [InternalBillingEntityController::class, 'index'])
            ->name('billing-entities.index');
        Route::post('/billing-entities', [InternalBillingEntityController::class, 'store'])
            ->name('billing-entities.store');
        Route::put('/billing-entities/{id}', [InternalBillingEntityController::class, 'update'])
            ->name('billing-entities.update');
        Route::post('/billing-entities/{id}/logo', [InternalBillingEntityController::class, 'uploadLogo'])
            ->name('billing-entities.logo');
        Route::delete('/billing-entities/{id}/logo', [InternalBillingEntityController::class, 'deleteLogo'])
            ->name('billing-entities.logo.delete');
        Route::delete('/billing-entities/{id}', [InternalBillingEntityController::class, 'destroy'])
            ->name('billing-entities.destroy');

        Route::get('/products', [InternalProductController::class, 'index'])->name('products.index');
        Route::post('/products', [InternalProductController::class, 'store'])->name('products.store');
        Route::put('/products/{id}', [InternalProductController::class, 'update'])->name('products.update');
        Route::post('/products/{id}/toggle', [InternalProductController::class, 'toggleActive'])->name('products.toggle');
        Route::post('/products/reorder', [InternalProductController::class, 'updateOrder'])->name('products.reorder');
        Route::get('/products/{id}/plans', [InternalProductController::class, 'plans'])->name('products.plans');

        Route::post('/plans', [InternalProductPlanController::class, 'store'])->name('plans.store');
        Route::put('/plans/{id}', [InternalProductPlanController::class, 'update'])->name('plans.update');
        Route::delete('/plans/{id}', [InternalProductPlanController::class, 'destroy'])->name('plans.destroy');
        Route::post('/plans/{id}/toggle', [InternalProductPlanController::class, 'toggleActive'])->name('plans.toggle');

        Route::post('/plan-categories', [InternalProductPlanCategoryController::class, 'store'])->name('plan-categories.store');
        Route::put('/plan-categories/{id}', [InternalProductPlanCategoryController::class, 'update'])->name('plan-categories.update');
        Route::delete('/plan-categories/{id}', [InternalProductPlanCategoryController::class, 'destroy'])->name('plan-categories.destroy');

        Route::post('/plan-prices', [InternalProductPlanPriceController::class, 'store'])->name('plan-prices.store');
        Route::put('/plan-prices/{id}', [InternalProductPlanPriceController::class, 'update'])->name('plan-prices.update');
        Route::delete('/plan-prices/{id}', [InternalProductPlanPriceController::class, 'destroy'])->name('plan-prices.destroy');
    });

    // Maavelus monthly revenue statements — internal-only, super_admin
    // gated. Each statement is an internal record of platform fees +
    // auto-generated referral commissions, not a customer-facing invoice.
    Route::middleware('role:super_admin')->prefix('maavelus/statements')->name('internal.maavelus-statements.')->group(function () {
        Route::get('/', [InternalMaavelusStatementController::class, 'index'])->name('index');
        Route::post('/', [InternalMaavelusStatementController::class, 'store'])->name('store');
        Route::get('/{id}', [InternalMaavelusStatementController::class, 'show'])->name('show');
        Route::post('/{id}/confirm', [InternalMaavelusStatementController::class, 'confirm'])->name('confirm');
        Route::get('/{id}/download', [InternalMaavelusStatementController::class, 'download'])->name('download');
        Route::delete('/{id}', [InternalMaavelusStatementController::class, 'destroy'])->name('destroy');
    });
});

/*
|--------------------------------------------------------------------------
| Group 2 — Portal (customer)
|--------------------------------------------------------------------------
*/
Route::prefix('account')->middleware('portal_auth')->group(function () {
    Route::get('/', PortalDashboardController::class)->name('portal.dashboard');
    Route::get('/products', [PortalProductController::class, 'index'])->name('portal.products');
    Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('portal.invoices');
    Route::get('/invoices/{id}/pdf', [PortalInvoiceController::class, 'downloadPdf'])->name('portal.invoices.pdf');
    Route::get('/invoices/{id}/preview-pdf', [PortalInvoiceController::class, 'previewPdf'])->name('portal.invoices.preview-pdf');
    Route::get('/support', [PortalSupportController::class, 'index'])->name('portal.support');
    Route::get('/settings', [PortalSettingsController::class, 'index'])->name('portal.settings');
});

// Placeholder portal login route so EnsurePortalUser's redirect() resolves.
Route::get('/account/login', fn () => 'Portal login (placeholder)')->name('portal.login');

/*
|--------------------------------------------------------------------------
| Group 3 — Referrer (partner)
|--------------------------------------------------------------------------
*/
Route::prefix('partners')->middleware(['auth', 'role:referrer'])->group(function () {
    Route::get('/', ReferrerDashboardController::class)->name('referrer.dashboard');
    Route::get('/customers', ReferrerCustomerController::class)->name('referrer.customers');
    Route::get('/commissions', ReferrerCommissionController::class)->name('referrer.commissions');
    Route::get('/payouts', ReferrerPayoutController::class)->name('referrer.payouts');
});

/*
|--------------------------------------------------------------------------
| Staff authentication (session-based, web guard)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [StaffLoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [StaffLoginController::class, 'login'])
        ->middleware('throttle:staff-login');
});

Route::post('/logout', [StaffLoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Webhooks (commented until controllers land)
|--------------------------------------------------------------------------
|
| Webhook routes must:
|   - bypass CSRF (no browser session)
|   - sit behind the vendor-specific signature middleware
|   - dedupe via WebhookIdempotencyService inside the controller
|
| Both routes intentionally stay commented out until the controllers
| exist; we don't want a 404-but-CSRF-exempt surface in production.
|
| Route::post('/webhooks/stripe', \App\Http\Controllers\Webhooks\StripeWebhookController::class)
//     ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
//     ->middleware([\App\Http\Middleware\VerifyStripeWebhook::class, 'throttle:120,1'])
//     ->name('webhooks.stripe');
|
| Route::post('/webhooks/postmark', \App\Http\Controllers\Webhooks\PostmarkWebhookController::class)
//     ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
//     ->middleware([\App\Http\Middleware\VerifyPostmarkWebhook::class, 'throttle:120,1'])
//     ->name('webhooks.postmark');
|
| Export endpoints (when built): throttle:10,1
| API endpoints (when built):    throttle:120,1
*/

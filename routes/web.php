<?php

use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\Internal\AnalyticsController as InternalAnalyticsController;
use App\Http\Controllers\Internal\BillingEntityController as InternalBillingEntityController;
use App\Http\Controllers\Internal\ContactController as InternalContactController;
use App\Http\Controllers\Internal\ContractController as InternalContractController;
use App\Http\Controllers\Internal\CustomerController as InternalCustomerController;
use App\Http\Controllers\Internal\CustomerGroupController as InternalCustomerGroupController;
use App\Http\Controllers\Internal\CustomerProductController as InternalCustomerProductController;
use App\Http\Controllers\Internal\DashboardController as InternalDashboardController;
use App\Http\Controllers\Internal\DomainController as InternalDomainController;
use App\Http\Controllers\Internal\ExpenseController as InternalExpenseController;
use App\Http\Controllers\Internal\FormBuilderController as InternalFormBuilderController;
use App\Http\Controllers\Internal\HelpController as InternalHelpController;
use App\Http\Controllers\Internal\ImpersonationController as InternalImpersonationController;
use App\Http\Controllers\Internal\InvoiceController as InternalInvoiceController;
use App\Http\Controllers\Internal\LeadController as InternalLeadController;
use App\Http\Controllers\Internal\MaavelusStatementController as InternalMaavelusStatementController;
use App\Http\Controllers\Internal\MilestoneController as InternalMilestoneController;
use App\Http\Controllers\Internal\MyAccountController as InternalMyAccountController;
use App\Http\Controllers\Internal\MyWorkController as InternalMyWorkController;
use App\Http\Controllers\Internal\NoteController as InternalNoteController;
use App\Http\Controllers\Internal\NotificationController as InternalNotificationController;
use App\Http\Controllers\Internal\PaymentScheduleController as InternalPaymentScheduleController;
use App\Http\Controllers\Internal\ProductController as InternalProductController;
use App\Http\Controllers\Internal\ProductOverviewController as InternalProductOverviewController;
use App\Http\Controllers\Internal\ProductPlanCategoryController as InternalProductPlanCategoryController;
use App\Http\Controllers\Internal\ProductPlanController as InternalProductPlanController;
use App\Http\Controllers\Internal\ProductPlanPriceController as InternalProductPlanPriceController;
use App\Http\Controllers\Internal\ProductSupplierController as InternalProductSupplierController;
use App\Http\Controllers\Internal\ProjectController as InternalProjectController;
use App\Http\Controllers\Internal\ProposalController as InternalProposalController;
use App\Http\Controllers\Internal\ProvisioningController as InternalProvisioningController;
use App\Http\Controllers\Internal\ReferrerController as InternalReferrerController;
use App\Http\Controllers\Internal\SearchController as InternalSearchController;
use App\Http\Controllers\Internal\SettingsController as InternalSettingsController;
use App\Http\Controllers\Internal\SubscriptionController as InternalSubscriptionController;
use App\Http\Controllers\Internal\SupplierController as InternalSupplierController;
use App\Http\Controllers\Internal\SupportController as InternalSupportController;
use App\Http\Controllers\Internal\TaskController as InternalTaskController;
use App\Http\Controllers\Internal\TimeEntryController as InternalTimeEntryController;
use App\Http\Controllers\Internal\WebsiteController as InternalWebsiteController;
use App\Http\Controllers\Internal\WorkflowController as InternalWorkflowController;
use App\Http\Controllers\OAuth\SuspensionController as OAuthSuspensionController;
use App\Http\Controllers\OAuth\UserInfoController as OAuthUserInfoController;
use App\Http\Controllers\Portal\AccountController as PortalAccountController;
use App\Http\Controllers\Portal\AuthController as PortalAuthController;
use App\Http\Controllers\Portal\ConnectedAppController as PortalConnectedAppController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\PasswordController as PortalPasswordController;
use App\Http\Controllers\Portal\ProductLaunchController as PortalProductLaunchController;
use App\Http\Controllers\Portal\ProductsController as PortalProductsController;
use App\Http\Controllers\Portal\SecurityController as PortalSecurityController;
use App\Http\Controllers\Portal\SubscriptionController as PortalSubscriptionController;
use App\Http\Controllers\Portal\SupportController as PortalSupportController;
use App\Http\Controllers\Public\EmbedController as PublicEmbedController;
use App\Http\Controllers\Public\FormController as PublicFormController;
use App\Http\Controllers\Public\ProposalAcceptanceController as PublicProposalAcceptanceController;
use App\Http\Controllers\Public\WebhookController as PublicWebhookController;
use App\Http\Controllers\Referrer\AccountController as ReferrerAccountController;
use App\Http\Controllers\Referrer\AuthController as ReferrerAuthController;
use App\Http\Controllers\Referrer\CommissionController as ReferrerCommissionController;
use App\Http\Controllers\Referrer\CustomerController as ReferrerCustomerController;
use App\Http\Controllers\Referrer\DashboardController as ReferrerDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Group 1 — Internal (staff)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'block_referrer', 'role:super_admin,staff'])->group(function () {
    Route::get('/', [InternalDashboardController::class, 'index'])->name('internal.dashboard');
    Route::get('/export/dashboard', [InternalDashboardController::class, 'export'])->name('internal.dashboard.export');

    // List/search endpoints — 60/min/user to slow bulk scraping
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/customers', [InternalCustomerController::class, 'index'])->name('internal.customers.index');
        Route::get('/invoices', [InternalInvoiceController::class, 'index'])->name('internal.invoices.index');
        Route::get('/referrers', [InternalReferrerController::class, 'index'])->name('internal.referrers.index');
        Route::get('/referrers/{id}', [InternalReferrerController::class, 'show'])
            ->whereNumber('id')
            ->name('internal.referrers.show');
        // Global topbar search — JSON endpoint, queries multiple
        // models with LIKE. Cheap enough at our scale to live under
        // the same throttle as the list endpoints.
        Route::get('/search', [InternalSearchController::class, 'search'])->name('internal.search');
    });

    // Mutations live outside the throttle group — bulk approvals can
    // genuinely fire several requests close together and they're behind
    // super_admin anyway.
    Route::middleware('role:super_admin')->prefix('referrers')->name('internal.referrers.')->group(function () {
        Route::post('/', [InternalReferrerController::class, 'store'])->name('store');
        Route::put('/{id}', [InternalReferrerController::class, 'update'])->name('update');
        Route::post('/{id}/reset-password', [InternalReferrerController::class, 'resetPassword'])->name('reset-password');
        Route::post('/approve-all', [InternalReferrerController::class, 'approveAll'])->name('approve-all');
        Route::post('/{id}/approve', [InternalReferrerController::class, 'approveCommission'])->name('approve');
        Route::post('/{id}/mark-paid', [InternalReferrerController::class, 'markPaid'])->name('mark-paid');
    });

    // Impersonation — mint a short-lived signed-token URL pointing at
    // the portal or referrer preview endpoint. super_admin only; the
    // preview endpoints live OUTSIDE the auth group because they're
    // the ones that establish the session on the other guard.
    Route::middleware('role:super_admin')->group(function () {
        Route::post('/impersonate/portal/{customerId}', [InternalImpersonationController::class, 'portalPreview'])->name('internal.impersonate.portal');
        Route::post('/impersonate/referrer/{referrerId}', [InternalImpersonationController::class, 'referrerPreview'])->name('internal.impersonate.referrer');
    });

    Route::post('/customers', [InternalCustomerController::class, 'store'])->name('internal.customers.store');
    Route::get('/customers/{id}', [InternalCustomerController::class, 'show'])->name('internal.customers.show');
    Route::put('/customers/{id}', [InternalCustomerController::class, 'update'])->name('internal.customers.update');
    Route::post('/customers/{id}/notes', [InternalCustomerController::class, 'storeNote'])->name('internal.customers.notes.store');

    // Contacts — full CRUD per customer, plus a "set primary" toggle.
    // Endpoint receives customer_id in the body so the controller can
    // authorise against the parent customer before touching the row.
    Route::post('/contacts', [InternalContactController::class, 'store'])->name('internal.contacts.store');
    Route::put('/contacts/{id}', [InternalContactController::class, 'update'])->name('internal.contacts.update');
    Route::delete('/contacts/{id}', [InternalContactController::class, 'destroy'])->name('internal.contacts.destroy');
    Route::post('/contacts/{id}/primary', [InternalContactController::class, 'setPrimary'])->name('internal.contacts.primary');

    // Contracts — staff CRUD with PDF upload via FileUploadService.
    // The download endpoint streams through Storage::disk('private')
    // so the file path is never exposed and the request is gated
    // through the parent Customer policy.
    Route::post('/contracts', [InternalContractController::class, 'store'])->name('internal.contracts.store');
    Route::post('/contracts/{id}', [InternalContractController::class, 'update'])
        ->whereNumber('id')
        ->name('internal.contracts.update');
    Route::delete('/contracts/{id}', [InternalContractController::class, 'destroy'])
        ->whereNumber('id')
        ->name('internal.contracts.destroy');
    Route::get('/contracts/{id}/download', [InternalContractController::class, 'download'])
        ->whereNumber('id')
        ->name('internal.contracts.download');

    // Customer groups (segments) — CRUD + membership endpoints. The
    // model is AccountGroup (legacy) but the URL + UI use the
    // "customer groups" vocabulary throughout.
    Route::get('/customer-groups', [InternalCustomerGroupController::class, 'index'])->name('internal.customer-groups.index');
    Route::post('/customer-groups', [InternalCustomerGroupController::class, 'store'])->name('internal.customer-groups.store');
    Route::put('/customer-groups/{id}', [InternalCustomerGroupController::class, 'update'])
        ->whereNumber('id')
        ->name('internal.customer-groups.update');
    Route::delete('/customer-groups/{id}', [InternalCustomerGroupController::class, 'destroy'])
        ->whereNumber('id')
        ->name('internal.customer-groups.destroy');
    Route::post('/customer-groups/{id}/members', [InternalCustomerGroupController::class, 'addMember'])
        ->whereNumber('id')
        ->name('internal.customer-groups.members.add');
    Route::delete('/customer-groups/{id}/members/{customerId}', [InternalCustomerGroupController::class, 'removeMember'])
        ->where(['id' => '[0-9]+', 'customerId' => '[0-9]+'])
        ->name('internal.customer-groups.members.remove');
    Route::post('/customers/{id}/tasks', [InternalCustomerController::class, 'storeTask'])->name('internal.customers.tasks.store');

    // Global task endpoints — for the dashboard New-task slide-over and
    // checkbox-completion on every list that surfaces tasks.
    Route::post('/tasks', [InternalTaskController::class, 'store'])->name('internal.tasks.store');
    Route::put('/tasks/{id}', [InternalTaskController::class, 'update'])->name('internal.tasks.update');
    Route::post('/tasks/{id}/complete', [InternalTaskController::class, 'complete'])->name('internal.tasks.complete');
    Route::post('/tasks/{id}/pin', [InternalTaskController::class, 'togglePin'])->name('internal.tasks.pin');
    Route::delete('/tasks/{id}', [InternalTaskController::class, 'destroy'])->name('internal.tasks.destroy');

    // Activity detail page (per task). /activities/{id} stays semantic
    // — "activity" is the user-facing word for a task even though the
    // controller is still TaskController.
    Route::get('/activities/{id}', [InternalTaskController::class, 'show'])
        ->whereNumber('id')
        ->name('internal.activities.show');

    // Notes — standalone CRUD used by the activity detail page's
    // notes thread. The legacy /customers/{id}/notes endpoint stays
    // for the customer-page note panel.
    Route::post('/notes', [InternalNoteController::class, 'store'])->name('internal.notes.store');
    Route::put('/notes/{id}', [InternalNoteController::class, 'update'])->name('internal.notes.update');
    Route::delete('/notes/{id}', [InternalNoteController::class, 'destroy'])->name('internal.notes.destroy');

    // ─── Project management ───
    // Projects + milestones + time tracking. All inside the existing
    // staff/super_admin role gate; the customer policy guards each
    // controller method individually.
    Route::prefix('projects')->name('internal.projects.')->group(function () {
        Route::get('/', [InternalProjectController::class, 'index'])->name('index');
        Route::post('/', [InternalProjectController::class, 'store'])->name('store');
        Route::get('/{id}', [InternalProjectController::class, 'show'])
            ->whereNumber('id')->name('show');
        Route::put('/{id}', [InternalProjectController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::delete('/{id}', [InternalProjectController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
        // Convert a set of unbilled, billable time entries into a
        // draft invoice. Lives on the project resource because the
        // selection is always "from one project".
        Route::post('/{id}/invoice', [InternalProjectController::class, 'generateInvoice'])
            ->whereNumber('id')->name('invoice.generate');
    });

    Route::prefix('milestones')->name('internal.milestones.')->group(function () {
        Route::post('/', [InternalMilestoneController::class, 'store'])->name('store');
        // Reorder is registered before {id} so it doesn't get
        // caught by the numeric route-model binding rule below.
        Route::post('/reorder', [InternalMilestoneController::class, 'reorder'])->name('reorder');
        Route::put('/{id}', [InternalMilestoneController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::delete('/{id}', [InternalMilestoneController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
    });

    // PM-only task endpoints. Distinct from /tasks above (which is
    // CRM-flavoured): kanban status changes and drag-drop reorder.
    Route::post('/tasks/reorder', [InternalTaskController::class, 'reorderTasks'])
        ->name('internal.tasks.reorder');
    Route::post('/tasks/{id}/status', [InternalTaskController::class, 'updateStatus'])
        ->whereNumber('id')->name('internal.tasks.status');

    Route::prefix('time-entries')->name('internal.time-entries.')->group(function () {
        Route::post('/', [InternalTimeEntryController::class, 'store'])->name('store');
        Route::put('/{id}', [InternalTimeEntryController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::delete('/{id}', [InternalTimeEntryController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
    });

    // Personal task dashboard — separate page, no per-user data leak
    // risk since the controller filters to auth()->id() unconditionally.
    Route::get('/my-work', [InternalMyWorkController::class, 'index'])->name('internal.my-work');

    // ─── Leads ───
    // Standalone pipeline — leads live in their own table and
    // don't appear in /customers until LeadController::convert
    // mints the customer row. Status update is JSON because the
    // kanban applies it optimistically; everything else is a
    // standard redirect-back round-trip.
    Route::prefix('leads')->name('internal.leads.')->group(function () {
        Route::get('/', [InternalLeadController::class, 'index'])->name('index');
        Route::post('/', [InternalLeadController::class, 'store'])->name('store');
        Route::get('/{id}', [InternalLeadController::class, 'show'])
            ->whereNumber('id')->name('show');
        Route::put('/{id}', [InternalLeadController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::post('/{id}/status', [InternalLeadController::class, 'updateStatus'])
            ->whereNumber('id')->name('status');
        Route::post('/{id}/convert', [InternalLeadController::class, 'convert'])
            ->whereNumber('id')->name('convert');
        Route::delete('/{id}', [InternalLeadController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
    });

    // ─── Forms (form builder) ───
    // The actual public endpoints (/forms/{slug}/submit,
    // /forms/{slug}/embed.js, /webhooks/{slug}) live OUTSIDE
    // this auth group at the bottom of the file.
    Route::prefix('forms')->name('internal.forms.')->group(function () {
        Route::get('/', [InternalFormBuilderController::class, 'index'])->name('index');
        Route::post('/', [InternalFormBuilderController::class, 'store'])->name('store');
        Route::put('/{id}', [InternalFormBuilderController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::delete('/{id}', [InternalFormBuilderController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
        Route::get('/{id}/submissions', [InternalFormBuilderController::class, 'submissions'])
            ->whereNumber('id')->name('submissions');
    });

    // ─── Workflows ───
    Route::prefix('workflows')->name('internal.workflows.')->group(function () {
        Route::get('/', [InternalWorkflowController::class, 'index'])->name('index');
        Route::post('/', [InternalWorkflowController::class, 'store'])->name('store');
        Route::put('/{id}', [InternalWorkflowController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::delete('/{id}', [InternalWorkflowController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
        Route::post('/{id}/toggle', [InternalWorkflowController::class, 'toggle'])
            ->whereNumber('id')->name('toggle');
    });

    // ─── Proposals ───
    // CRUD + send + download + convert to contract. The public-side
    // acceptance flow lives outside this auth group (see below).
    Route::prefix('proposals')->name('internal.proposals.')->group(function () {
        Route::get('/', [InternalProposalController::class, 'index'])->name('index');
        Route::post('/', [InternalProposalController::class, 'store'])->name('store');
        Route::get('/{id}', [InternalProposalController::class, 'show'])
            ->whereNumber('id')->name('show');
        Route::delete('/{id}', [InternalProposalController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
        Route::post('/{id}/send', [InternalProposalController::class, 'send'])
            ->whereNumber('id')->name('send');
        Route::get('/{id}/pdf', [InternalProposalController::class, 'downloadPdf'])
            ->whereNumber('id')->name('pdf');
        Route::get('/{id}/accepted-pdf', [InternalProposalController::class, 'downloadAcceptedPdf'])
            ->whereNumber('id')->name('accepted-pdf');
        Route::post('/{id}/convert', [InternalProposalController::class, 'convertToContract'])
            ->whereNumber('id')->name('convert');
    });

    // Payment schedules attach to a proposal or project. The
    // store path lives at the resource root; manual item-trigger
    // is its own POST so it can be called from the proposal Show
    // page's per-item button without nesting URLs.
    Route::post('/payment-schedules', [InternalPaymentScheduleController::class, 'store'])
        ->name('internal.payment-schedules.store');
    Route::post('/payment-schedules/items/{itemId}/trigger', [InternalPaymentScheduleController::class, 'triggerItem'])
        ->whereNumber('itemId')
        ->name('internal.payment-schedules.items.trigger');

    // ─── Expenses ───
    // CRUD + approval workflow. Approval is gated to super_admin
    // inside the controller; the rest of the methods sit behind the
    // surrounding staff/super_admin role group.
    Route::prefix('expenses')->name('internal.expenses.')->group(function () {
        Route::get('/', [InternalExpenseController::class, 'index'])->name('index');
        Route::post('/', [InternalExpenseController::class, 'store'])->name('store');
        Route::put('/{id}', [InternalExpenseController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::delete('/{id}', [InternalExpenseController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
        Route::post('/{id}/approve', [InternalExpenseController::class, 'approve'])
            ->whereNumber('id')->name('approve');
        Route::post('/{id}/mark-paid', [InternalExpenseController::class, 'markPaid'])
            ->whereNumber('id')->name('mark-paid');
        Route::get('/{id}/receipt', [InternalExpenseController::class, 'receipt'])
            ->whereNumber('id')->name('receipt');
    });

    // ─── Suppliers ───
    // Vendor register. CRUD only; deletion is blocked server-side when
    // expenses reference the supplier (deactivate instead).
    Route::prefix('suppliers')->name('internal.suppliers.')->group(function () {
        Route::get('/', [InternalSupplierController::class, 'index'])->name('index');
        Route::post('/', [InternalSupplierController::class, 'store'])->name('store');
        Route::put('/{id}', [InternalSupplierController::class, 'update'])
            ->whereNumber('id')->name('update');
        Route::delete('/{id}', [InternalSupplierController::class, 'destroy'])
            ->whereNumber('id')->name('destroy');
    });

    // My account — staff/super_admin self-service profile + password.
    // No role:super_admin gate; every staff member needs this.
    Route::get('/account', [InternalMyAccountController::class, 'show'])->name('internal.account.show');
    Route::put('/account', [InternalMyAccountController::class, 'update'])->name('internal.account.update');
    Route::put('/account/password', [InternalMyAccountController::class, 'updatePassword'])->name('internal.account.password');
    Route::put('/account/notifications', [InternalMyAccountController::class, 'updateNotifications'])->name('internal.account.notifications');

    // In-app notifications — bell dropdown + full-page list. read-all is
    // declared before the {id} routes so it can never be swallowed by the
    // UUID param. {id} is a notifications-table UUID.
    Route::get('/notifications', [InternalNotificationController::class, 'index'])->name('internal.notifications.index');
    Route::post('/notifications/read-all', [InternalNotificationController::class, 'markAllRead'])->name('internal.notifications.read-all');
    Route::post('/notifications/{id}/read', [InternalNotificationController::class, 'markRead'])
        ->where('id', '[a-zA-Z0-9-]+')
        ->name('internal.notifications.read');
    Route::delete('/notifications/{id}', [InternalNotificationController::class, 'destroy'])
        ->where('id', '[a-zA-Z0-9-]+')
        ->name('internal.notifications.destroy');
    Route::delete('/customers/{id}/archive', [InternalCustomerController::class, 'archive'])->name('internal.customers.archive');

    // Portal access — issue or rotate portal credentials for a customer.
    // Temp password flashes back once; staff must relay it manually.
    Route::post('/customers/{id}/invite-portal', [InternalCustomerController::class, 'inviteToPortal'])->name('internal.customers.invite-portal');
    Route::post('/customers/{id}/portal-users/{portalUserId}/revoke', [InternalCustomerController::class, 'revokePortalAccess'])->name('internal.customers.revoke-portal');

    // Referral tear-down is super_admin-only. The action voids
    // pending commissions and detaches the referrer permanently —
    // nothing a regular staff member should be able to trigger on
    // their own.
    Route::middleware('role:super_admin')->group(function () {
        Route::post('/customers/{id}/referral', [InternalCustomerController::class, 'addReferral'])->name('internal.customers.referral.add');
        Route::delete('/customers/{id}/referral', [InternalCustomerController::class, 'removeReferral'])->name('internal.customers.referral.remove');
    });

    // Product subscriptions on a customer — enabling a new product creates
    // a CustomerProduct, suspending removes their access. Both stay open
    // to staff (alongside super_admin) so account managers can wire up
    // a new sub without escalating every time.
    Route::post('/customers/{id}/products', [InternalCustomerController::class, 'enableProduct'])->name('internal.customers.products.enable');
    Route::post('/customers/{id}/products/{productId}/suspend', [InternalCustomerController::class, 'suspendProduct'])->name('internal.customers.products.suspend');

    // Reasoned suspend / reinstate of a single subscription (fires the
    // product webhook + records who acted). Operates on the CustomerProduct id.
    Route::post('/customer-products/{id}/suspend', [InternalCustomerProductController::class, 'suspend'])
        ->whereNumber('id')->name('internal.customer-products.suspend');
    Route::post('/customer-products/{id}/reinstate', [InternalCustomerProductController::class, 'reinstate'])
        ->whereNumber('id')->name('internal.customer-products.reinstate');

    // Toggle a customer's auto-suspension exemption (super_admin only).
    Route::post('/customers/{id}/exemption', [InternalCustomerController::class, 'toggleExemption'])
        ->whereNumber('id')->middleware('role:super_admin')->name('internal.customers.exemption');

    // Manual re-queue of a failed/abandoned webhook delivery.
    Route::post('/webhooks/deliveries/{id}/retry', [InternalSettingsController::class, 'retryWebhookDelivery'])
        ->whereNumber('id')->name('internal.webhooks.deliveries.retry');

    // ─── Websites (cPanel / WHM / PageSpeed) ───
    // Managed from the customer detail Websites tab.
    Route::post('/websites', [InternalWebsiteController::class, 'store'])->name('internal.websites.store');
    Route::put('/websites/{id}', [InternalWebsiteController::class, 'update'])
        ->whereNumber('id')->name('internal.websites.update');
    Route::delete('/websites/{id}', [InternalWebsiteController::class, 'destroy'])
        ->whereNumber('id')->name('internal.websites.destroy');
    Route::post('/websites/{id}/sync-hosting', [InternalWebsiteController::class, 'syncHosting'])
        ->whereNumber('id')->name('internal.websites.sync-hosting');
    Route::post('/websites/{id}/check-pagespeed', [InternalWebsiteController::class, 'checkPageSpeed'])
        ->whereNumber('id')->name('internal.websites.check-pagespeed');

    Route::get('/invoices/new', [InternalInvoiceController::class, 'create'])->name('internal.invoices.create');
    Route::post('/invoices', [InternalInvoiceController::class, 'store'])->name('internal.invoices.store');
    // `{id}` is constrained to digits so literal segments (e.g.
    // /invoices/new — declared above) and probes never fall through to
    // show() and trip its `int $id` type hint with a 500. Bad IDs 404.
    Route::get('/invoices/{id}', [InternalInvoiceController::class, 'show'])->whereNumber('id')->name('internal.invoices.show');
    Route::get('/invoices/{id}/edit', [InternalInvoiceController::class, 'edit'])->whereNumber('id')->name('internal.invoices.edit');
    Route::put('/invoices/{id}', [InternalInvoiceController::class, 'update'])->whereNumber('id')->name('internal.invoices.update');
    Route::get('/invoices/{id}/pdf', [InternalInvoiceController::class, 'downloadPdf'])->whereNumber('id')->name('internal.invoices.pdf');
    Route::get('/invoices/{id}/preview-pdf', [InternalInvoiceController::class, 'previewPdf'])->whereNumber('id')->name('internal.invoices.preview-pdf');
    Route::post('/invoices/{id}/mark-paid', [InternalInvoiceController::class, 'markPaid'])->whereNumber('id')->name('internal.invoices.mark-paid');
    Route::post('/invoices/{id}/void', [InternalInvoiceController::class, 'voidInvoice'])->whereNumber('id')->name('internal.invoices.void');
    Route::post('/invoices/{id}/send', [InternalInvoiceController::class, 'sendInvoice'])->whereNumber('id')->name('internal.invoices.send');
    Route::post('/invoices/{id}/send-reminder', [InternalInvoiceController::class, 'sendReminder'])->whereNumber('id')->name('internal.invoices.send-reminder');
    Route::post('/invoices/{id}/pause-reminders', [InternalInvoiceController::class, 'pauseReminders'])->whereNumber('id')->name('internal.invoices.pause-reminders');
    Route::post('/invoices/{id}/resume-reminders', [InternalInvoiceController::class, 'resumeReminders'])->whereNumber('id')->name('internal.invoices.resume-reminders');
    // Cancels a recurring template. Doesn't void the row — just stops
    // the artisan generator from cloning it again.
    Route::post('/invoices/{id}/stop-recurring', [InternalInvoiceController::class, 'stopRecurring'])->whereNumber('id')->name('internal.invoices.stop-recurring');
    Route::get('/domains', [InternalDomainController::class, 'index'])->name('internal.domains.index');
    // WHOIS lookup — fires from the Add/Edit slide-over BEFORE the
    // domain row exists, so it has no {id} segment. Sits ahead of
    // the {id}-bound routes below.
    Route::post('/domains/whois-lookup', [InternalDomainController::class, 'whoisLookup'])
        ->name('internal.domains.whois');
    Route::post('/domains', [InternalDomainController::class, 'store'])->name('internal.domains.store');
    Route::put('/domains/{id}', [InternalDomainController::class, 'update'])
        ->whereNumber('id')
        ->name('internal.domains.update');
    Route::delete('/domains/{id}', [InternalDomainController::class, 'destroy'])
        ->whereNumber('id')
        ->name('internal.domains.destroy');
    Route::post('/domains/{id}/check', [InternalDomainController::class, 'checkHealth'])
        ->whereNumber('id')
        ->name('internal.domains.check');
    Route::get('/domains/{id}/dns', [InternalDomainController::class, 'dnsRecords'])
        ->whereNumber('id')
        ->name('internal.domains.dns');
    Route::get('/analytics', [InternalAnalyticsController::class, 'index'])->name('internal.analytics.index');

    // Product overview pages — one per product, navigated to from
    // the sidebar Products section. Lives outside the settings group
    // because it's read-only for staff (no super_admin gate).
    Route::get('/products/{slug}', [InternalProductOverviewController::class, 'show'])->name('internal.products.show');
    Route::get('/support', [InternalSupportController::class, 'index'])->name('internal.support.index');
    Route::post('/support', [InternalSupportController::class, 'store'])->name('internal.support.store');
    Route::get('/support/{id}', [InternalSupportController::class, 'show'])->name('internal.support.show');
    Route::post('/support/{id}/reply', [InternalSupportController::class, 'reply'])->name('internal.support.reply');
    Route::post('/support/{id}/status', [InternalSupportController::class, 'updateStatus'])->name('internal.support.status');
    // Spin a CRM activity off a ticket — the new task is scoped to
    // the ticket's customer and an internal note is appended.
    Route::post('/support/{id}/task', [InternalSupportController::class, 'createTask'])->name('internal.support.task.create');

    // Help & docs — staff editor + viewer for the support_knowledge_base
    // articles. The customer portal has its own help routes (TBD) that
    // filter on is_public=true.
    Route::get('/help', [InternalHelpController::class, 'index'])->name('internal.help.index');
    Route::post('/help', [InternalHelpController::class, 'store'])->name('internal.help.store');
    Route::get('/help/{slug}', [InternalHelpController::class, 'show'])->name('internal.help.show');
    Route::put('/help/{id}', [InternalHelpController::class, 'update'])->name('internal.help.update');
    Route::delete('/help/{id}', [InternalHelpController::class, 'destroy'])->name('internal.help.destroy');

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
        // Billing automation — auto-suspension thresholds.
        Route::get('/billing', [InternalSettingsController::class, 'billing'])->name('billing');
        Route::post('/billing', [InternalSettingsController::class, 'billingUpdate'])->name('billing.update');
        // Reminder templates — edit subject/body per escalation tier
        // and preview against the most recent invoice.
        Route::get('/reminder-templates', [InternalSettingsController::class, 'reminderTemplates'])->name('reminder-templates');
        Route::put('/reminder-templates/{id}', [InternalSettingsController::class, 'reminderTemplatesUpdate'])
            ->whereNumber('id')
            ->name('reminder-templates.update');
        Route::post('/reminder-templates/{id}/preview', [InternalSettingsController::class, 'reminderTemplatesPreview'])
            ->whereNumber('id')
            ->name('reminder-templates.preview');

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

        // Product cost lines — the product_suppliers pivot. Managed from
        // the product detail page for margin tracking.
        Route::get('/products/{id}/suppliers', [InternalProductSupplierController::class, 'index'])
            ->whereNumber('id')->name('products.suppliers.index');
        Route::post('/products/{id}/suppliers', [InternalProductSupplierController::class, 'store'])
            ->whereNumber('id')->name('products.suppliers.store');
        Route::put('/products/{id}/suppliers/{supplierId}', [InternalProductSupplierController::class, 'update'])
            ->whereNumber('id')->whereNumber('supplierId')->name('products.suppliers.update');
        Route::delete('/products/{id}/suppliers/{supplierId}', [InternalProductSupplierController::class, 'destroy'])
            ->whereNumber('id')->whereNumber('supplierId')->name('products.suppliers.destroy');

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
| Public proposal acceptance — NO auth
|--------------------------------------------------------------------------
| Token-only authorisation. The token is single-use: accept() nulls it
| out, so a re-visit shows the success page rather than the form again.
*/
Route::get('/proposals/accept/{token}', [PublicProposalAcceptanceController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->name('proposal.accept.show');
Route::post('/proposals/accept/{token}', [PublicProposalAcceptanceController::class, 'accept'])
    ->where('token', '[a-f0-9]{64}')
    ->name('proposal.accept.submit');

/*
|--------------------------------------------------------------------------
| Public form endpoints — NO auth, CSRF excluded in bootstrap/app.php
|--------------------------------------------------------------------------
| Three surfaces, one slug:
|   GET  /forms/{slug}/embed.js   — JavaScript widget for any site
|   POST /forms/{slug}/submit     — browser form post (honeypot + rate-limited)
|   POST /webhooks/{slug}         — system-to-system, HMAC-signed
|
| Slug regex matches the form builder's validation rule.
*/
Route::get('/forms/{slug}/embed.js', [PublicEmbedController::class, 'script'])
    ->where('slug', '[a-z0-9-]+')
    ->name('form.embed.script');
Route::post('/forms/{slug}/submit', [PublicFormController::class, 'submit'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:30,1')
    ->name('form.submit');
Route::post('/webhooks/{slug}', [PublicWebhookController::class, 'receive'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware(['form.webhook', 'throttle:120,1'])
    ->name('form.webhook.receive');

/*
|--------------------------------------------------------------------------
| OAuth — consumer-app endpoints (token-authenticated)
|--------------------------------------------------------------------------
| Passport already exposes /oauth/authorize, /oauth/token, etc.
| These two are *our* additions: a userinfo profile call and a
| product-access map. Both use auth:api (Passport guard, portal_users
| provider). 60/min per token is plenty for normal consumer-app use
| and slows abuse if a token leaks.
*/
Route::middleware(['auth:api', 'throttle:60,1'])
    ->prefix('oauth')
    ->name('oauth.')
    ->group(function () {
        Route::get('/userinfo', [OAuthUserInfoController::class, 'me'])->name('userinfo');
        Route::get('/products', [OAuthUserInfoController::class, 'products'])->name('products');
    });

// Branded suspension page. The authorize-route middleware renders this
// inline when a customer is suspended; this named route is the direct
// deep-link (portal session required, not a token).
Route::get('/oauth/suspended', [OAuthSuspensionController::class, 'show'])
    ->middleware(['web', 'auth.portal'])
    ->name('oauth.suspended');

/*
|--------------------------------------------------------------------------
| Group 2 — Portal (customer)
|--------------------------------------------------------------------------
*/
// Guest-only portal auth routes. portal_guest sends an already-signed-in
// portal session straight to the dashboard so login pages aren't re-served.
Route::prefix('portal')->middleware('portal_guest')->group(function () {
    Route::get('/login', [PortalAuthController::class, 'showLogin'])->name('portal.login');
    Route::post('/login', [PortalAuthController::class, 'login'])->name('portal.login.submit');

    // Password reset is part of the guest surface — once logged in,
    // a portal user changes their password from /portal/account.
    Route::get('/forgot-password', [PortalPasswordController::class, 'showForgotForm'])->name('portal.forgot-password');
    Route::post('/forgot-password', [PortalPasswordController::class, 'sendResetLink'])->name('portal.forgot-password.submit');
    Route::get('/reset-password', [PortalPasswordController::class, 'showResetForm'])->name('portal.reset-password');
    Route::post('/reset-password', [PortalPasswordController::class, 'resetPassword'])->name('portal.reset-password.submit');
});

// Preview entrypoint sits outside portal_guest because the staff
// member who launched the preview might already hold a portal session
// from an earlier visit — the controller swaps the active user.
Route::get('/portal/preview', [PortalAuthController::class, 'preview'])->name('portal.preview');

// Authenticated portal area. auth.portal alias maps to EnsurePortalUser —
// every controller here can rely on Auth::guard('portal')->user() being set.
Route::prefix('portal')->middleware('auth.portal')->group(function () {
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('portal.logout');

    Route::get('/dashboard', PortalDashboardController::class)->name('portal.dashboard');

    // SSO sprint — product access map + connected-app revoke.
    Route::get('/products', [PortalProductsController::class, 'index'])->name('portal.products');
    Route::post('/connected-apps/{clientId}/revoke', [PortalConnectedAppController::class, 'revoke'])
        ->where('clientId', '[a-f0-9-]{36}')
        ->name('portal.connected-apps.revoke');

    // Server-side SSO launcher — mints a per-launch token, hands it
    // to the consumer app's exchange endpoint, redirects to the
    // one-time URL we get back. Used by Dashboard "Open" button.
    Route::post('/launch/{slug}', [PortalProductLaunchController::class, 'launch'])
        ->where('slug', '[a-z0-9-]+')
        ->name('portal.product.launch');

    // Security page — password change form + per-token revoke list.
    // The password endpoint already lives on AccountController so we
    // reuse it; this page just renders the form against the same URL.
    Route::get('/security', [PortalSecurityController::class, 'index'])->name('portal.security');
    Route::delete('/security/tokens/{token}', [PortalSecurityController::class, 'revokeToken'])
        ->where('token', '[a-zA-Z0-9]+')
        ->name('portal.security.tokens.revoke');

    Route::get('/subscriptions', [PortalSubscriptionController::class, 'index'])->name('portal.subscriptions.index');
    Route::post('/subscriptions', [PortalSubscriptionController::class, 'store'])->name('portal.subscriptions.store');
    Route::post('/subscriptions/{id}/cancel', [PortalSubscriptionController::class, 'cancel'])->name('portal.subscriptions.cancel');

    Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('portal.invoices.index');
    Route::get('/invoices/{id}/pdf', [PortalInvoiceController::class, 'downloadPdf'])->name('portal.invoices.pdf');
    Route::get('/invoices/{id}/preview-pdf', [PortalInvoiceController::class, 'previewPdf'])->name('portal.invoices.preview-pdf');

    Route::get('/support', [PortalSupportController::class, 'index'])->name('portal.support.index');
    Route::post('/support', [PortalSupportController::class, 'store'])->name('portal.support.store');
    Route::get('/support/{id}', [PortalSupportController::class, 'show'])->name('portal.support.show');
    Route::post('/support/{id}/reply', [PortalSupportController::class, 'reply'])->name('portal.support.reply');

    Route::get('/account', [PortalAccountController::class, 'index'])->name('portal.account.index');
    Route::put('/account', [PortalAccountController::class, 'update'])->name('portal.account.update');
    Route::put('/account/password', [PortalAccountController::class, 'updatePassword'])->name('portal.account.password');
});

// Bare alias /portal so account.whitedash.co.uk/ lands somewhere useful
// without redirect chains. Goes through portal_guest so unauthenticated
// hits jump straight to /portal/login.
Route::get('/portal', fn () => redirect()->route('portal.dashboard'))
    ->middleware('auth.portal');

/*
|--------------------------------------------------------------------------
| Group 3 — Referrer (partner)
|--------------------------------------------------------------------------
*/
// Partner-facing portal. Same 'web' guard as staff (referrers are
// users with role=referrer), but a dedicated /referrer prefix +
// role:referrer middleware keeps them firmly out of /internal/*.
Route::middleware(['auth', 'role:referrer'])
    ->prefix('referrer')
    ->name('referrer.')
    ->group(function () {
        Route::get('/dashboard', ReferrerDashboardController::class)->name('dashboard');
        Route::get('/commissions', [ReferrerCommissionController::class, 'index'])->name('commissions');
        Route::get('/customers', [ReferrerCustomerController::class, 'index'])->name('customers');

        Route::get('/account', [ReferrerAccountController::class, 'index'])->name('account');
        Route::put('/account', [ReferrerAccountController::class, 'update'])->name('account.update');
        Route::put('/account/password', [ReferrerAccountController::class, 'updatePassword'])->name('account.password');
        Route::put('/account/payment', [ReferrerAccountController::class, 'updatePayment'])->name('account.payment');
    });

// Bare /referrer → dashboard so the URL bar without a path lands somewhere.
Route::get('/referrer', fn () => redirect()->route('referrer.dashboard'))
    ->middleware(['auth', 'role:referrer']);

// Referrer preview entrypoint — no role middleware because the
// handler itself establishes the new session before any role check
// can read it. Token validation provides the access gate.
Route::get('/referrer/preview', [ReferrerAuthController::class, 'preview'])->name('referrer.preview');

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

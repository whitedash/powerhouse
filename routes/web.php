<?php

use App\Http\Controllers\Auth\StaffLoginController;
use App\Http\Controllers\Internal\AnalyticsController as InternalAnalyticsController;
use App\Http\Controllers\Internal\BillingEntityController as InternalBillingEntityController;
use App\Http\Controllers\Internal\CommissionRuleController as InternalCommissionRuleController;
use App\Http\Controllers\Internal\CompanyController as InternalCustomerController;
use App\Http\Controllers\Internal\ContactController as InternalContactController;
use App\Http\Controllers\Internal\ContractController as InternalContractController;
use App\Http\Controllers\Internal\CustomerGroupController as InternalCustomerGroupController;
use App\Http\Controllers\Internal\CustomerProductController as InternalCustomerProductController;
use App\Http\Controllers\Internal\DashboardController as InternalDashboardController;
use App\Http\Controllers\Internal\DeploymentController as InternalDeploymentController;
use App\Http\Controllers\Internal\DomainController as InternalDomainController;
use App\Http\Controllers\Internal\ExpenseController as InternalExpenseController;
use App\Http\Controllers\Internal\FormBuilderController as InternalFormBuilderController;
use App\Http\Controllers\Internal\FormThemeController as InternalFormThemeController;
use App\Http\Controllers\Internal\GdprController as InternalGdprController;
use App\Http\Controllers\Internal\GoogleCalendarAuthController as InternalGoogleCalendarAuthController;
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
use App\Http\Controllers\Internal\PersonController as InternalPersonController;
use App\Http\Controllers\Internal\ProductController as InternalProductController;
use App\Http\Controllers\Internal\ProductOverviewController as InternalProductOverviewController;
use App\Http\Controllers\Internal\ProductPlanCategoryController as InternalProductPlanCategoryController;
use App\Http\Controllers\Internal\ProductPlanController as InternalProductPlanController;
use App\Http\Controllers\Internal\ProductPlanPriceController as InternalProductPlanPriceController;
use App\Http\Controllers\Internal\ProductSupplierController as InternalProductSupplierController;
use App\Http\Controllers\Internal\ProjectController as InternalProjectController;
use App\Http\Controllers\Internal\ProjectFileController as InternalProjectFileController;
use App\Http\Controllers\Internal\ProposalController as InternalProposalController;
use App\Http\Controllers\Internal\ProvisioningController as InternalProvisioningController;
use App\Http\Controllers\Internal\ReferralLedgerController as InternalReferralLedgerController;
use App\Http\Controllers\Internal\ReferrerController as InternalReferrerController;
use App\Http\Controllers\Internal\RoleController as InternalRoleController;
use App\Http\Controllers\Internal\SearchController as InternalSearchController;
use App\Http\Controllers\Internal\SettingsController as InternalSettingsController;
use App\Http\Controllers\Internal\SubscriptionController as InternalSubscriptionController;
use App\Http\Controllers\Internal\SupplierController as InternalSupplierController;
use App\Http\Controllers\Internal\SupportController as InternalSupportController;
use App\Http\Controllers\Internal\TaskController as InternalTaskController;
use App\Http\Controllers\Internal\TimeEntryController as InternalTimeEntryController;
use App\Http\Controllers\Internal\WebsiteController as InternalWebsiteController;
use App\Http\Controllers\Internal\WordPressUpdateController as InternalWordPressUpdateController;
use App\Http\Controllers\Internal\WorkflowController as InternalWorkflowController;
use App\Http\Controllers\OAuth\SuspensionController as OAuthSuspensionController;
use App\Http\Controllers\OAuth\UserInfoController as OAuthUserInfoController;
use App\Http\Controllers\Portal\AccountController as PortalAccountController;
use App\Http\Controllers\Portal\AssetController as PortalAssetController;
use App\Http\Controllers\Portal\AuthController as PortalAuthController;
use App\Http\Controllers\Portal\ConnectedAppController as PortalConnectedAppController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboardController;
use App\Http\Controllers\Portal\FormController as PortalFormController;
use App\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use App\Http\Controllers\Portal\PasswordController as PortalPasswordController;
use App\Http\Controllers\Portal\PaymentMethodController as PortalPaymentMethodController;
use App\Http\Controllers\Portal\ProductLaunchController as PortalProductLaunchController;
use App\Http\Controllers\Portal\ProductsController as PortalProductsController;
use App\Http\Controllers\Portal\SubscriptionController as PortalSubscriptionController;
use App\Http\Controllers\Portal\SupportController as PortalSupportController;
use App\Http\Controllers\Public\EmbedController as PublicEmbedController;
use App\Http\Controllers\Public\FormController as PublicFormController;
use App\Http\Controllers\Public\FormDraftController;
use App\Http\Controllers\Public\InboundEmailController as PublicInboundEmailController;
use App\Http\Controllers\Public\KnowledgeBaseController as PublicKnowledgeBaseController;
use App\Http\Controllers\Public\LandingController as PublicLandingController;
use App\Http\Controllers\Public\LegalController as PublicLegalController;
use App\Http\Controllers\Public\PlanCheckoutController as PublicPlanCheckoutController;
use App\Http\Controllers\Public\PlanEmbedController as PublicPlanEmbedController;
use App\Http\Controllers\Public\ProposalAcceptanceController as PublicProposalAcceptanceController;
use App\Http\Controllers\Public\ProposalRejectionController as PublicProposalRejectionController;
use App\Http\Controllers\Public\ReferralRedirectController as PublicReferralRedirectController;
use App\Http\Controllers\Public\SupportTicketController as PublicSupportTicketController;
use App\Http\Controllers\Public\WebhookController as PublicWebhookController;
use App\Http\Controllers\Referrer\AccountController as ReferrerAccountController;
use App\Http\Controllers\Referrer\AuthController as ReferrerAuthController;
use App\Http\Controllers\Referrer\CommissionController as ReferrerCommissionController;
use App\Http\Controllers\Referrer\CompanyController as ReferrerCustomerController;
use App\Http\Controllers\Referrer\DashboardController as ReferrerDashboardController;
use App\Http\Controllers\Referrer\ReferralDealController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Middleware\VerifyStripeWebhook;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Group 1 — Internal (staff)
|--------------------------------------------------------------------------
*/
// Public hub front door. Branches by auth state in the controller: guests see
// the marketing landing; authenticated users are redirected to their role home.
// No auth middleware — it must be reachable logged-out.
Route::get('/', PublicLandingController::class)->name('home');

// Public knowledge base. Read-only, unauthenticated; controller scopes every
// query to is_public && is_published. show() throttled since each hit writes
// a views counter.
Route::get('/kb', [PublicKnowledgeBaseController::class, 'index'])->name('kb.index');
Route::get('/kb/{slug}', [PublicKnowledgeBaseController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:60,1')
    ->name('kb.show');

// Public submit-ticket. Unauthenticated; honeypot + Turnstile + throttle
// guard the POST. Creation is handled by TicketIntakeService.
Route::get('/support', [PublicSupportTicketController::class, 'create'])->name('support.create');
Route::post('/support', [PublicSupportTicketController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('support.store');
Route::get('/support/submitted', [PublicSupportTicketController::class, 'submitted'])->name('support.submitted');

// Public legal pages, linked from the PublicLayout footer. Unauthenticated,
// static long-form content rendered by PublicLegalController.
Route::get('/privacy', [PublicLegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [PublicLegalController::class, 'terms'])->name('legal.terms');
Route::get('/cookies', [PublicLegalController::class, 'cookies'])->name('legal.cookies');

Route::middleware(['auth', 'block_referrer', 'role:super_admin,staff'])->group(function () {
    // Internal dashboard moved off "/" (now the public front door). The name
    // stays internal.dashboard so every route('internal.dashboard') caller is
    // unaffected; only the path changed.
    Route::get('/dashboard', [InternalDashboardController::class, 'index'])->name('internal.dashboard');
    Route::get('/export/dashboard', [InternalDashboardController::class, 'export'])->name('internal.dashboard.export');

    // List/search endpoints — 60/min/user to slow bulk scraping
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/companies', [InternalCustomerController::class, 'index'])->name('internal.companies.index');
        Route::get('/invoices', [InternalInvoiceController::class, 'index'])->name('internal.invoices.index');
        // Referrer ledger reads gate referrers.access (sprint step 10); the
        // /referrals attribution ledger uses the same permission (no separate
        // referrals.access). Mutations/commission stay referrers.manage +
        // commission.approve below.
        Route::get('/referrers', [InternalReferrerController::class, 'index'])
            ->middleware('permission:referrers.access')->name('internal.referrers.index');
        // Referral attribution ledger — every customer_referrals row,
        // filterable. Read-only; same scraping throttle as the lists.
        Route::get('/referrals', [InternalReferralLedgerController::class, 'index'])
            ->middleware('permission:referrers.access')->name('internal.referrals.index');
        Route::get('/referrers/{id}', [InternalReferrerController::class, 'show'])
            ->whereNumber('id')
            ->middleware('permission:referrers.access')
            ->name('internal.referrers.show');
        // Global topbar search — JSON endpoint, queries multiple
        // models with LIKE. Cheap enough at our scale to live under
        // the same throttle as the list endpoints.
        Route::get('/search', [InternalSearchController::class, 'search'])->name('internal.search');
        // People — cross-company human identity. List sits under the
        // same scraping throttle as /customers.
        Route::get('/people', [InternalPersonController::class, 'index'])->name('internal.people.index');
    });

    // Mutations live outside the throttle group — bulk approvals can
    // genuinely fire several requests close together and they're behind
    // super_admin anyway.
    Route::prefix('referrers')->name('internal.referrers.')->group(function () {
        Route::middleware('permission:referrers.manage')->group(function () {
            Route::post('/', [InternalReferrerController::class, 'store'])->name('store');
            Route::put('/{id}', [InternalReferrerController::class, 'update'])->name('update');
            Route::post('/{id}/reset-password', [InternalReferrerController::class, 'resetPassword'])->name('reset-password');
        });
        Route::middleware('permission:commission.approve')->group(function () {
            Route::post('/approve-all', [InternalReferrerController::class, 'approveAll'])->name('approve-all');
            Route::post('/{id}/approve', [InternalReferrerController::class, 'approveCommission'])->name('approve');
            Route::post('/{id}/mark-paid', [InternalReferrerController::class, 'markPaid'])->name('mark-paid');
        });
    });

    // Impersonation — mint a short-lived signed-token URL pointing at
    // the portal or referrer preview endpoint. super_admin only; the
    // preview endpoints live OUTSIDE the auth group because they're
    // the ones that establish the session on the other guard.
    Route::middleware('permission:impersonate')->group(function () {
        Route::post('/impersonate/portal/{customerId}', [InternalImpersonationController::class, 'portalPreview'])->name('internal.impersonate.portal');
        Route::post('/impersonate/referrer/{referrerId}', [InternalImpersonationController::class, 'referrerPreview'])->name('internal.impersonate.referrer');
    });

    Route::post('/companies', [InternalCustomerController::class, 'store'])->name('internal.companies.store');
    Route::get('/companies/{id}', [InternalCustomerController::class, 'show'])->name('internal.companies.show');
    Route::put('/companies/{id}', [InternalCustomerController::class, 'update'])->name('internal.companies.update');
    Route::post('/companies/{id}/notes', [InternalCustomerController::class, 'storeNote'])->name('internal.companies.notes.store');

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
    // through the parent Company policy.
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

    // Company groups (segments) — CRUD + membership endpoints. The
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
    // People — cross-company human identity (the "one person owns many
    // companies" layer). CRUD plus company association endpoints; the
    // customer_person pivot carries the role + job_title.
    Route::get('/people/{id}', [InternalPersonController::class, 'show'])
        ->whereNumber('id')
        ->name('internal.people.show');
    Route::post('/people', [InternalPersonController::class, 'store'])->name('internal.people.store');
    Route::put('/people/{id}', [InternalPersonController::class, 'update'])
        ->whereNumber('id')
        ->name('internal.people.update');
    Route::delete('/people/{id}', [InternalPersonController::class, 'destroy'])
        ->whereNumber('id')
        ->name('internal.people.destroy');
    Route::post('/people/{id}/companies', [InternalPersonController::class, 'attachCompany'])
        ->whereNumber('id')
        ->name('internal.people.companies.attach');
    Route::put('/people/{id}/companies/{customerId}', [InternalPersonController::class, 'setRole'])
        ->where(['id' => '[0-9]+', 'customerId' => '[0-9]+'])
        ->name('internal.people.companies.role');
    Route::delete('/people/{id}/companies/{customerId}', [InternalPersonController::class, 'detachCompany'])
        ->where(['id' => '[0-9]+', 'customerId' => '[0-9]+'])
        ->name('internal.people.companies.detach');

    Route::post('/companies/{id}/tasks', [InternalCustomerController::class, 'storeTask'])->name('internal.companies.tasks.store');

    // Global task endpoints — for the dashboard New-task slide-over and
    // checkbox-completion on every list that surfaces tasks.
    Route::post('/tasks', [InternalTaskController::class, 'store'])->name('internal.tasks.store');
    // JSON feed for the task form's "Link to" (lead/customer) picker.
    // Throttled like the other list/search endpoints to slow scraping.
    Route::get('/tasks/link-options', [InternalTaskController::class, 'linkOptions'])
        ->middleware('throttle:60,1')
        ->name('internal.tasks.link_options');
    Route::put('/tasks/{id}', [InternalTaskController::class, 'update'])->name('internal.tasks.update');
    Route::post('/tasks/{id}/complete', [InternalTaskController::class, 'complete'])->name('internal.tasks.complete');
    Route::post('/tasks/{id}/pin', [InternalTaskController::class, 'togglePin'])->name('internal.tasks.pin');
    Route::delete('/tasks/{id}', [InternalTaskController::class, 'destroy'])->name('internal.tasks.destroy');

    // Task file attachments. Upload is scoped to a task id; download +
    // destroy act on the attachment id. The literal 'attachments' segment
    // can't collide with the numeric {id} routes — these are two-segment
    // paths and the new ones pin {id} to digits with whereNumber.
    Route::post('/tasks/{id}/attachments', [InternalTaskController::class, 'uploadAttachment'])
        ->whereNumber('id')->name('internal.tasks.attachments.upload');
    Route::get('/tasks/attachments/{id}/download', [InternalTaskController::class, 'downloadAttachment'])
        ->whereNumber('id')->name('internal.tasks.attachments.download');
    Route::delete('/tasks/attachments/{id}', [InternalTaskController::class, 'destroyAttachment'])
        ->whereNumber('id')->name('internal.tasks.attachments.destroy');

    // Activity detail page (per task). /activities/{id} stays semantic
    // — "activity" is the user-facing word for a task even though the
    // controller is still TaskController.
    Route::get('/activities/{id}', [InternalTaskController::class, 'show'])
        ->whereNumber('id')
        ->name('internal.activities.show');

    // Notes — standalone CRUD used by the activity detail page's
    // notes thread. The legacy /companies/{id}/notes endpoint stays
    // for the customer-page note panel.
    Route::post('/notes', [InternalNoteController::class, 'store'])->name('internal.notes.store');
    Route::put('/notes/{id}', [InternalNoteController::class, 'update'])->name('internal.notes.update');
    Route::delete('/notes/{id}', [InternalNoteController::class, 'destroy'])->name('internal.notes.destroy');

    // ─── Project management ───
    // Projects + milestones + time tracking. All inside the existing
    // staff/super_admin role gate; the customer policy guards each
    // controller method individually.
    Route::prefix('projects')->name('internal.projects.')->group(function () {
        // Reads (index/show/files.download) stay scope-only; mutations require
        // projects.manage, composing with the in-method Projects scope +
        // ownership/invoiced/invoices.manage checks.
        Route::get('/', [InternalProjectController::class, 'index'])->name('index');
        Route::post('/', [InternalProjectController::class, 'store'])
            ->middleware('permission:projects.manage')->name('store');
        Route::get('/{id}', [InternalProjectController::class, 'show'])
            ->whereNumber('id')->name('show');
        Route::put('/{id}', [InternalProjectController::class, 'update'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('update');
        Route::delete('/{id}', [InternalProjectController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('destroy');
        // Convert a set of unbilled, billable time entries into a
        // draft invoice. Lives on the project resource because the
        // selection is always "from one project". (Also keeps its
        // in-method invoices.manage gate — Issue-A.)
        Route::post('/{id}/invoice', [InternalProjectController::class, 'generateInvoice'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('invoice.generate');

        // Project files — upload (scanned async), secure download, delete.
        // The /files/{fileId} paths are two-segment so they never collide
        // with the numeric /{id} project routes above.
        Route::post('/{id}/files', [InternalProjectFileController::class, 'upload'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('files.upload');
        Route::get('/files/{fileId}/download', [InternalProjectFileController::class, 'download'])
            ->whereNumber('fileId')->name('files.download');
        Route::delete('/files/{fileId}', [InternalProjectFileController::class, 'destroy'])
            ->whereNumber('fileId')->middleware('permission:projects.manage')->name('files.destroy');
    });

    Route::prefix('milestones')->name('internal.milestones.')->group(function () {
        Route::post('/', [InternalMilestoneController::class, 'store'])
            ->middleware('permission:projects.manage')->name('store');
        // Reorder is registered before {id} so it doesn't get
        // caught by the numeric route-model binding rule below.
        Route::post('/reorder', [InternalMilestoneController::class, 'reorder'])
            ->middleware('permission:projects.manage')->name('reorder');
        Route::put('/{id}', [InternalMilestoneController::class, 'update'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('update');
        Route::delete('/{id}', [InternalMilestoneController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('destroy');
    });

    // PM-only task endpoints. Distinct from /tasks above (which is
    // CRM-flavoured): kanban status changes and drag-drop reorder.
    Route::post('/tasks/reorder', [InternalTaskController::class, 'reorderTasks'])
        ->name('internal.tasks.reorder');
    Route::post('/tasks/{id}/status', [InternalTaskController::class, 'updateStatus'])
        ->whereNumber('id')->name('internal.tasks.status');
    // Drag-to-reschedule from the MyWork calendar (JSON).
    Route::post('/tasks/{id}/reschedule', [InternalTaskController::class, 'reschedule'])
        ->whereNumber('id')->name('internal.tasks.reschedule');
    // One-click complete + reschedule from the My Work list (Inertia redirects).
    Route::post('/tasks/{id}/quick-complete', [InternalTaskController::class, 'quickComplete'])
        ->whereNumber('id')->name('internal.tasks.quick-complete');
    Route::post('/tasks/{id}/quick-reschedule', [InternalTaskController::class, 'quickReschedule'])
        ->whereNumber('id')->name('internal.tasks.quick-reschedule');

    Route::prefix('time-entries')->name('internal.time-entries.')->group(function () {
        Route::post('/', [InternalTimeEntryController::class, 'store'])
            ->middleware('permission:projects.manage')->name('store');
        Route::put('/{id}', [InternalTimeEntryController::class, 'update'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('update');
        Route::delete('/{id}', [InternalTimeEntryController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:projects.manage')->name('destroy');
    });

    // Personal task dashboard — separate page, no per-user data leak
    // risk since the controller filters to auth()->id() unconditionally.
    Route::get('/my-work', [InternalMyWorkController::class, 'index'])->name('internal.my-work');
    // JSON feed for the MyWork Calendar tab (FullCalendar fetches it
    // directly; scoped to auth()->id() in the controller).
    Route::get('/my-work/calendar', [InternalMyWorkController::class, 'calendar'])->name('internal.my-work.calendar');

    // ─── Leads ───
    // Standalone pipeline — leads live in their own table and
    // don't appear in /customers until LeadController::convert
    // mints the customer row. Status update is JSON because the
    // kanban applies it optimistically; everything else is a
    // standard redirect-back round-trip.
    Route::prefix('leads')->name('internal.leads.')->group(function () {
        // Reads (index/show) stay scope-only. The 5 CRUD/convert mutations
        // require leads.manage (composing with the in-method Leads scope).
        // approve/reject already gate leads.manage in-method via LeadPolicy::review
        // — left alone (no double-gate). convert ALSO requires customers.manage
        // in-method (it mints a Company — see LeadController::convert).
        Route::get('/', [InternalLeadController::class, 'index'])->name('index');
        Route::post('/', [InternalLeadController::class, 'store'])
            ->middleware('permission:leads.manage')->name('store');
        Route::post('/import', [InternalLeadController::class, 'import'])
            ->middleware('permission:leads.manage')->name('import');
        Route::get('/import/template', [InternalLeadController::class, 'importTemplate'])
            ->middleware('permission:leads.manage')->name('import.template');
        Route::get('/{id}', [InternalLeadController::class, 'show'])
            ->whereNumber('id')->name('show');
        Route::put('/{id}', [InternalLeadController::class, 'update'])
            ->whereNumber('id')->middleware('permission:leads.manage')->name('update');
        Route::post('/{id}/status', [InternalLeadController::class, 'updateStatus'])
            ->whereNumber('id')->middleware('permission:leads.manage')->name('status');
        Route::post('/{id}/convert', [InternalLeadController::class, 'convert'])
            ->whereNumber('id')->middleware('permission:leads.manage')->name('convert');
        // Deal-registration review actions (already gate leads.manage via LeadPolicy::review).
        Route::post('/{id}/referral/approve', [InternalLeadController::class, 'approveReferral'])
            ->whereNumber('id')->name('referral.approve');
        Route::post('/{id}/referral/reject', [InternalLeadController::class, 'rejectReferral'])
            ->whereNumber('id')->name('referral.reject');
        Route::delete('/{id}', [InternalLeadController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:leads.manage')->name('destroy');
    });

    // ─── Forms (form builder) ───
    // The actual public endpoints (/forms/{slug}/submit,
    // /forms/{slug}/embed.js, /webhooks/{slug}) live OUTSIDE
    // this auth group at the bottom of the file.
    // Reads gate forms.access; mutations gate forms.manage; the submissions
    // PII read keeps its own forms.view_submissions gate (Issue-A). The group
    // mixes reads and mutations, so each route carries its own middleware
    // (the proposals pattern). Theme routes self-gate via FormThemePolicy.
    Route::prefix('forms')->name('internal.forms.')->group(function () {
        Route::get('/', [InternalFormBuilderController::class, 'index'])
            ->middleware('permission:forms.access')->name('index');
        Route::post('/', [InternalFormBuilderController::class, 'store'])
            ->middleware('permission:forms.manage')->name('store');

        // Reusable design themes (the design editor). Defined BEFORE the
        // /{id} routes; the {id} routes are whereNumber so "themes" never
        // collides with them.
        Route::prefix('themes')->name('themes.')->group(function () {
            Route::get('/', [InternalFormThemeController::class, 'index'])->name('index');
            Route::post('/', [InternalFormThemeController::class, 'store'])->name('store');
            Route::put('/{id}', [InternalFormThemeController::class, 'update'])
                ->whereNumber('id')->name('update');
            Route::delete('/{id}', [InternalFormThemeController::class, 'destroy'])
                ->whereNumber('id')->name('destroy');
        });

        Route::put('/{id}', [InternalFormBuilderController::class, 'update'])
            ->whereNumber('id')->middleware('permission:forms.manage')->name('update');
        Route::delete('/{id}', [InternalFormBuilderController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:forms.manage')->name('destroy');
        Route::get('/{id}/submissions', [InternalFormBuilderController::class, 'submissions'])
            ->whereNumber('id')->middleware('permission:forms.view_submissions')->name('submissions');
    });

    // ─── Workflows ───
    // Read gates workflows.access; mutations gate workflows.manage. workflows.manage
    // is the automation control point: WorkflowEngine executes on events (system
    // authority), so gating who can BUILD/EDIT/toggle a workflow is what controls
    // the ungated per-execution automation actions (create_lead/update_lead_status/
    // assign_to_user, create_ticket, create_task) deferred in sprint steps 5/7/8.
    Route::prefix('workflows')->name('internal.workflows.')->group(function () {
        Route::get('/', [InternalWorkflowController::class, 'index'])
            ->middleware('permission:workflows.access')->name('index');
        Route::post('/', [InternalWorkflowController::class, 'store'])
            ->middleware('permission:workflows.manage')->name('store');
        Route::put('/{id}', [InternalWorkflowController::class, 'update'])
            ->whereNumber('id')->middleware('permission:workflows.manage')->name('update');
        Route::delete('/{id}', [InternalWorkflowController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:workflows.manage')->name('destroy');
        Route::post('/{id}/toggle', [InternalWorkflowController::class, 'toggle'])
            ->whereNumber('id')->middleware('permission:workflows.manage')->name('toggle');
    });

    // ─── Proposals ───
    // CRUD + send + download + convert to contract. The public-side
    // acceptance flow lives outside this auth group (see below).
    Route::prefix('proposals')->name('internal.proposals.')->group(function () {
        // Reads require proposals.access; mutations require proposals.manage.
        // Both compose with the in-method viewAny(Company) = customers.access.
        Route::get('/', [InternalProposalController::class, 'index'])
            ->middleware('permission:proposals.access')->name('index');
        Route::post('/', [InternalProposalController::class, 'store'])
            ->middleware('permission:proposals.manage')->name('store');
        Route::get('/{id}', [InternalProposalController::class, 'show'])
            ->whereNumber('id')->middleware('permission:proposals.access')->name('show');
        Route::delete('/{id}', [InternalProposalController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:proposals.manage')->name('destroy');
        Route::post('/{id}/send', [InternalProposalController::class, 'send'])
            ->whereNumber('id')->middleware('permission:proposals.manage')->name('send');
        Route::post('/{id}/regenerate-link', [InternalProposalController::class, 'regenerateLink'])
            ->whereNumber('id')->middleware('permission:proposals.manage')->name('regenerate-link');
        Route::get('/{id}/pdf', [InternalProposalController::class, 'downloadPdf'])
            ->whereNumber('id')->middleware('permission:proposals.access')->name('pdf');
        Route::get('/{id}/accepted-pdf', [InternalProposalController::class, 'downloadAcceptedPdf'])
            ->whereNumber('id')->middleware('permission:proposals.access')->name('accepted-pdf');
        Route::post('/{id}/convert', [InternalProposalController::class, 'convertToContract'])
            ->whereNumber('id')->middleware('permission:proposals.manage')->name('convert');
    });

    // Payment schedules attach to a proposal or project. The
    // store path lives at the resource root; manual item-trigger
    // is its own POST so it can be called from the proposal Show
    // page's per-item button without nesting URLs.
    Route::post('/payment-schedules', [InternalPaymentScheduleController::class, 'store'])
        ->middleware('permission:proposals.manage')->name('internal.payment-schedules.store');
    Route::post('/payment-schedules/items/{itemId}/trigger', [InternalPaymentScheduleController::class, 'triggerItem'])
        ->whereNumber('itemId')
        ->name('internal.payment-schedules.items.trigger');

    // ─── Expenses ───
    // CRUD + approval workflow. Approval is gated to super_admin
    // inside the controller; the rest of the methods sit behind the
    // surrounding staff/super_admin role group.
    Route::prefix('expenses')->name('internal.expenses.')->group(function () {
        // Reads (index, receipt) gate expenses.access (sprint step 10). Mutations
        // require expenses.manage; approve keeps its expenses.approve-only gate
        // (separation of duties).
        Route::get('/', [InternalExpenseController::class, 'index'])
            ->middleware('permission:expenses.access')->name('index');
        Route::post('/', [InternalExpenseController::class, 'store'])
            ->middleware('permission:expenses.manage')->name('store');
        Route::put('/{id}', [InternalExpenseController::class, 'update'])
            ->whereNumber('id')->middleware('permission:expenses.manage')->name('update');
        Route::delete('/{id}', [InternalExpenseController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:expenses.manage')->name('destroy');
        Route::post('/{id}/approve', [InternalExpenseController::class, 'approve'])
            ->whereNumber('id')->name('approve');
        Route::post('/{id}/mark-paid', [InternalExpenseController::class, 'markPaid'])
            ->whereNumber('id')->middleware('permission:expenses.manage')->name('mark-paid');
        Route::get('/{id}/receipt', [InternalExpenseController::class, 'receipt'])
            ->whereNumber('id')->middleware('permission:expenses.access')->name('receipt');
    });

    // ─── Suppliers ───
    // Vendor register. CRUD only; deletion is blocked server-side when
    // expenses reference the supplier (deactivate instead).
    Route::prefix('suppliers')->name('internal.suppliers.')->group(function () {
        // Supplier register read (feeds the expense form) — gate expenses.access.
        Route::get('/', [InternalSupplierController::class, 'index'])
            ->middleware('permission:expenses.access')->name('index');
        Route::post('/', [InternalSupplierController::class, 'store'])
            ->middleware('permission:expenses.manage')->name('store');
        Route::put('/{id}', [InternalSupplierController::class, 'update'])
            ->whereNumber('id')->middleware('permission:expenses.manage')->name('update');
        Route::delete('/{id}', [InternalSupplierController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:expenses.manage')->name('destroy');
    });

    // My account — staff/super_admin self-service profile + password.
    // No role:super_admin gate; every staff member needs this.
    Route::get('/account', [InternalMyAccountController::class, 'show'])->name('internal.account.show');
    Route::put('/account', [InternalMyAccountController::class, 'update'])->name('internal.account.update');
    Route::put('/account/password', [InternalMyAccountController::class, 'updatePassword'])->name('internal.account.password');
    Route::put('/account/notifications', [InternalMyAccountController::class, 'updateNotifications'])->name('internal.account.notifications');

    // Per-user Google Calendar connection (OAuth offline flow).
    Route::get('/account/google/connect', [InternalGoogleCalendarAuthController::class, 'redirect'])->name('google.calendar.redirect');
    Route::get('/account/google/callback', [InternalGoogleCalendarAuthController::class, 'callback'])->name('google.calendar.callback');
    Route::post('/account/google/disconnect', [InternalGoogleCalendarAuthController::class, 'disconnect'])->name('google.calendar.disconnect');

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
    Route::delete('/companies/{id}/archive', [InternalCustomerController::class, 'archive'])->name('internal.companies.archive');

    // Portal access — issue or rotate portal credentials for a customer.
    // Temp password flashes back once; staff must relay it manually.
    Route::post('/companies/{id}/invite-portal', [InternalCustomerController::class, 'inviteToPortal'])->name('internal.companies.invite-portal');
    Route::post('/companies/{id}/portal-users/{portalUserId}/revoke', [InternalCustomerController::class, 'revokePortalAccess'])->name('internal.companies.revoke-portal');

    // Referral tear-down is super_admin-only. The action voids
    // pending commissions and detaches the referrer permanently —
    // nothing a regular staff member should be able to trigger on
    // their own.
    Route::middleware('permission:companies.referral.manage')->group(function () {
        Route::post('/companies/{id}/referral', [InternalCustomerController::class, 'addReferral'])->name('internal.companies.referral.add');
        Route::delete('/companies/{id}/referral', [InternalCustomerController::class, 'removeReferral'])->name('internal.companies.referral.remove');
    });

    // GDPR tooling — right to erasure (Art. 17) + data portability (Art. 20).
    // super_admin only: erasure is irreversible and the export is full PII.
    Route::prefix('gdpr/companies')->name('internal.gdpr.')->group(function () {
        Route::middleware('permission:gdpr.erase')->group(function () {
            Route::post('/{id}/request-erasure', [InternalGdprController::class, 'requestErasure'])
                ->whereNumber('id')->name('request-erasure');
            Route::post('/{id}/process-erasure', [InternalGdprController::class, 'processErasure'])
                ->whereNumber('id')->name('process-erasure');
        });
        Route::get('/{id}/export', [InternalGdprController::class, 'exportData'])
            ->whereNumber('id')->middleware('permission:gdpr.export')->name('export');
    });

    // Product subscriptions on a customer — enabling a new product creates
    // a CustomerProduct, suspending removes their access. These are
    // provisioning mutations: they require provisioning.manage (section gate)
    // and compose with the per-customer customers.manage check in-method.
    Route::post('/companies/{id}/products', [InternalCustomerController::class, 'enableProduct'])
        ->middleware('permission:provisioning.manage')->name('internal.companies.products.enable');
    Route::post('/companies/{id}/products/{productId}/suspend', [InternalCustomerController::class, 'suspendProduct'])
        ->middleware('permission:provisioning.manage')->name('internal.companies.products.suspend');

    // Reasoned suspend / reinstate of a single subscription (fires the
    // product webhook + records who acted). Operates on the CustomerProduct id.
    Route::post('/customer-products/{id}/suspend', [InternalCustomerProductController::class, 'suspend'])
        ->whereNumber('id')->middleware('permission:provisioning.manage')->name('internal.customer-products.suspend');
    Route::post('/customer-products/{id}/reinstate', [InternalCustomerProductController::class, 'reinstate'])
        ->whereNumber('id')->middleware('permission:provisioning.manage')->name('internal.customer-products.reinstate');
    Route::put('/customer-products/{id}', [InternalCustomerProductController::class, 'update'])
        ->whereNumber('id')->middleware('permission:provisioning.manage')->name('internal.customer-products.update');

    // Plans widget review gate: confirm a pending self-serve purchase
    // (status 'pending' → 'active') and send the receipt withheld at
    // webhook provisioning. Double-gated like the suspend/reinstate pair
    // above: provisioning.manage (section gate, route middleware)
    // composing with the per-company companies.manage check in-method
    // (CompanyPolicy::update) — both must pass.
    Route::post('/companies/{company}/customer-products/{customerProduct}/confirm', [InternalCustomerProductController::class, 'confirm'])
        ->whereNumber('company')->whereNumber('customerProduct')->middleware('permission:provisioning.manage')
        ->name('internal.customer-products.confirm');

    // Toggle a customer's auto-suspension exemption (super_admin only).
    Route::post('/companies/{id}/exemption', [InternalCustomerController::class, 'toggleExemption'])
        ->whereNumber('id')->middleware('permission:companies.exemption')->name('internal.companies.exemption');
    // Billing P1: staff toggle of the per-customer auto-collect intent.
    Route::post('/companies/{id}/auto-collect', [InternalCustomerController::class, 'updateAutoCollect'])
        ->whereNumber('id')->name('internal.companies.auto-collect');

    // Manual re-queue of a failed/abandoned webhook delivery.
    Route::post('/webhooks/deliveries/{id}/retry', [InternalSettingsController::class, 'retryWebhookDelivery'])
        ->whereNumber('id')->middleware('permission:settings.integrations')->name('internal.webhooks.deliveries.retry');

    // ─── Websites (cPanel / WHM / PageSpeed) ───
    // Managed from the customer detail Websites tab. All 8 are WRITES → require
    // permission:hosting.manage (the section capability). Each method also gates
    // the per-customer Gate::authorize('update', $website->customer) IDOR guard
    // (CompanyPolicy::update = customers.manage) — composed, both must pass.
    Route::post('/websites', [InternalWebsiteController::class, 'store'])
        ->middleware('permission:hosting.manage')->name('internal.websites.store');
    Route::put('/websites/{id}', [InternalWebsiteController::class, 'update'])
        ->whereNumber('id')->middleware('permission:hosting.manage')->name('internal.websites.update');
    Route::delete('/websites/{id}', [InternalWebsiteController::class, 'destroy'])
        ->whereNumber('id')->middleware('permission:hosting.manage')->name('internal.websites.destroy');
    Route::post('/websites/{id}/sync-hosting', [InternalWebsiteController::class, 'syncHosting'])
        ->whereNumber('id')->middleware('permission:hosting.manage')->name('internal.websites.sync-hosting');
    Route::post('/websites/{id}/check-pagespeed', [InternalWebsiteController::class, 'checkPageSpeed'])
        ->whereNumber('id')->middleware('permission:hosting.manage')->name('internal.websites.check-pagespeed');
    Route::post('/websites/{id}/sync-wordpress', [InternalWebsiteController::class, 'syncWordPress'])
        ->whereNumber('id')->middleware('permission:hosting.manage')->name('internal.websites.sync-wordpress');
    Route::post('/websites/{id}/suspend-hosting', [InternalWebsiteController::class, 'suspendHosting'])
        ->whereNumber('id')->middleware('permission:hosting.manage')->name('internal.websites.suspend-hosting');
    Route::post('/websites/{id}/reinstate-hosting', [InternalWebsiteController::class, 'reinstateHosting'])
        ->whereNumber('id')->middleware('permission:hosting.manage')->name('internal.websites.reinstate-hosting');

    // ─── WordPress bulk plugin updates (MainWP) ───
    // super_admin only — mutates live customer sites. The page lists sites
    // with outstanding updates; the run endpoint updates one site at a time
    // so the UI can show live per-site progress.
    Route::middleware('permission:wordpress.bulk_update')->prefix('wordpress')->group(function () {
        Route::get('/updates', [InternalWordPressUpdateController::class, 'index'])
            ->name('internal.wordpress.updates');
        Route::post('/updates/site/{id}', [InternalWordPressUpdateController::class, 'updateSite'])
            ->whereNumber('id')->name('internal.wordpress.updates.site');
        Route::post('/updates/core', [InternalWordPressUpdateController::class, 'updateCore'])
            ->name('internal.wordpress.updates.core');
        Route::post('/updates/plugin/update', [InternalWordPressUpdateController::class, 'updatePlugin'])
            ->name('internal.wordpress.updates.plugin.update');
        Route::post('/updates/plugin/bulk', [InternalWordPressUpdateController::class, 'updatePluginAcrossSites'])
            ->name('internal.wordpress.updates.plugin.bulk');
        Route::post('/updates/theme/update', [InternalWordPressUpdateController::class, 'updateTheme'])
            ->name('internal.wordpress.updates.theme.update');
        Route::post('/updates/plugin/toggle', [InternalWordPressUpdateController::class, 'togglePlugin'])
            ->name('internal.wordpress.updates.plugin.toggle');
    });

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
    Route::post('/invoices/{id}/payment-link', [InternalInvoiceController::class, 'generatePaymentLink'])->whereNumber('id')->name('internal.invoices.payment-link');
    Route::post('/invoices/{id}/void', [InternalInvoiceController::class, 'voidInvoice'])->whereNumber('id')->name('internal.invoices.void');
    Route::post('/invoices/{id}/send', [InternalInvoiceController::class, 'sendInvoice'])->whereNumber('id')->name('internal.invoices.send');
    Route::post('/invoices/{id}/send-reminder', [InternalInvoiceController::class, 'sendReminder'])->whereNumber('id')->name('internal.invoices.send-reminder');
    Route::post('/invoices/{id}/pause-reminders', [InternalInvoiceController::class, 'pauseReminders'])->whereNumber('id')->name('internal.invoices.pause-reminders');
    Route::post('/invoices/{id}/resume-reminders', [InternalInvoiceController::class, 'resumeReminders'])->whereNumber('id')->name('internal.invoices.resume-reminders');
    // Cancels a recurring template. Doesn't void the row — just stops
    // the artisan generator from cloning it again.
    Route::post('/invoices/{id}/stop-recurring', [InternalInvoiceController::class, 'stopRecurring'])->whereNumber('id')->name('internal.invoices.stop-recurring');
    // ─── Domains (registrar / WHOIS / DNS / SSL health) ───
    // READS gate permission:hosting.access; WRITES gate permission:hosting.manage.
    // The write routes (store/update/destroy/check) were previously gated only by
    // the in-method customers.access (viewAny) — a WRITE behind a READ perm; the
    // hosting.manage middleware closes that. The in-method customers.access check
    // is preserved (composed) — see DomainController.
    Route::get('/domains', [InternalDomainController::class, 'index'])
        ->middleware('permission:hosting.access')->name('internal.domains.index');
    // WHOIS lookup — fires from the Add/Edit slide-over BEFORE the
    // domain row exists, so it has no {id} segment. Read-only lookup →
    // hosting.access. Sits ahead of the {id}-bound routes below.
    Route::post('/domains/whois-lookup', [InternalDomainController::class, 'whoisLookup'])
        ->middleware('permission:hosting.access')->name('internal.domains.whois');
    Route::post('/domains', [InternalDomainController::class, 'store'])
        ->middleware('permission:hosting.manage')->name('internal.domains.store');
    Route::put('/domains/{id}', [InternalDomainController::class, 'update'])
        ->whereNumber('id')
        ->middleware('permission:hosting.manage')
        ->name('internal.domains.update');
    Route::delete('/domains/{id}', [InternalDomainController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('permission:hosting.manage')
        ->name('internal.domains.destroy');
    Route::post('/domains/{id}/check', [InternalDomainController::class, 'checkHealth'])
        ->whereNumber('id')
        ->middleware('permission:hosting.manage')
        ->name('internal.domains.check');
    Route::get('/domains/{id}/dns', [InternalDomainController::class, 'dnsRecords'])
        ->whereNumber('id')
        ->middleware('permission:hosting.access')
        ->name('internal.domains.dns');
    Route::get('/analytics', [InternalAnalyticsController::class, 'index'])->middleware('permission:analytics.access')->name('internal.analytics.index');

    // Product overview pages — one per product, navigated to from
    // the sidebar Products section. Base access requires provisioning.access
    // (it's a product/provisioning surface); the financial KPIs on the page are
    // further redacted in-method unless the user holds analytics.access (step 11).
    Route::get('/products/{slug}', [InternalProductOverviewController::class, 'show'])
        ->middleware('permission:provisioning.access')->name('internal.products.show');
    // Staff support desk. Moved off /support → /helpdesk so the bare
    // /support path can host the PUBLIC submit-ticket form. Route NAMES stay
    // internal.support.* so every route() reference is unaffected; only the
    // paths changed (same approach as the / → /dashboard move).
    // Reads (index/show) stay scope-only (authorizeScopeSection/Item in-method).
    // The 4 mutations require permission:support.manage, COMPOSING with the
    // in-method Support scope + support.view_unassigned predicate (both must
    // pass). createTask additionally keeps its in-method Tasks-section scope gate.
    Route::get('/helpdesk', [InternalSupportController::class, 'index'])->name('internal.support.index');
    Route::post('/helpdesk', [InternalSupportController::class, 'store'])
        ->middleware('permission:support.manage')->name('internal.support.store');
    Route::get('/helpdesk/{id}', [InternalSupportController::class, 'show'])->name('internal.support.show');
    Route::post('/helpdesk/{id}/reply', [InternalSupportController::class, 'reply'])
        ->middleware('permission:support.manage')->name('internal.support.reply');
    Route::post('/helpdesk/{id}/status', [InternalSupportController::class, 'updateStatus'])
        ->middleware('permission:support.manage')->name('internal.support.status');
    // Spin a CRM activity off a ticket — the new task is scoped to
    // the ticket's customer and an internal note is appended.
    Route::post('/helpdesk/{id}/task', [InternalSupportController::class, 'createTask'])
        ->middleware('permission:support.manage')->name('internal.support.task.create');

    // Help & docs — staff editor + viewer for the support_knowledge_base
    // articles. The customer portal has its own help routes (TBD) that
    // filter on is_public=true.
    // Staff KB editor. Reads gate knowledge_base.access, mutations knowledge_base.manage.
    // NB: routes are named internal.help.* but the PERMISSION tokens are knowledge_base.*
    // (the public /kb viewer in Public\KnowledgeBaseController stays ungated).
    Route::get('/help', [InternalHelpController::class, 'index'])
        ->middleware('permission:knowledge_base.access')->name('internal.help.index');
    Route::post('/help', [InternalHelpController::class, 'store'])
        ->middleware('permission:knowledge_base.manage')->name('internal.help.store');
    Route::get('/help/{slug}', [InternalHelpController::class, 'show'])
        ->middleware('permission:knowledge_base.access')->name('internal.help.show');
    Route::put('/help/{id}', [InternalHelpController::class, 'update'])
        ->middleware('permission:knowledge_base.manage')->name('internal.help.update');
    Route::delete('/help/{id}', [InternalHelpController::class, 'destroy'])
        ->middleware('permission:knowledge_base.manage')->name('internal.help.destroy');

    Route::get('/provisioning', [InternalProvisioningController::class, 'index'])->middleware('permission:provisioning.access')->name('internal.provisioning.index');
    Route::post('/provisioning/toggle', [InternalProvisioningController::class, 'toggle'])->middleware('permission:provisioning.manage')->name('internal.provisioning.toggle');

    Route::get('/subscriptions', [InternalSubscriptionController::class, 'index'])->middleware('permission:provisioning.access')->name('internal.subscriptions.index');
    Route::put('/subscriptions/{id}', [InternalSubscriptionController::class, 'update'])->middleware('permission:provisioning.manage')->name('internal.subscriptions.update');
    Route::post('/subscriptions/{id}/cancel', [InternalSubscriptionController::class, 'cancel'])->middleware('permission:provisioning.manage')->name('internal.subscriptions.cancel');
    Route::get('/settings', [InternalSettingsController::class, 'index'])
        ->middleware('permission:settings.access')->name('internal.settings.index');

    // Settings sub-pages that mutate global config (billing entities, etc.)
    // are super_admin-only. Staff can read /settings overview but not the
    // sensitive editors. Nested middleware extends — not replaces — the
    // outer auth + role:super_admin,staff guard.
    // Phase 3a: the settings admin sub-pages were one role:super_admin group.
    // They now split into per-capability permission: sub-groups (super_admin
    // holds every permission, so it still reaches all; staff holds none of
    // these, so its reach is unchanged). The /settings overview (GET /settings,
    // above) now requires permission:settings.access (sprint step 10) — seeded
    // staff hold it; the sub-pages keep their own per-capability gates below.
    Route::prefix('settings')->name('internal.settings.')->group(function () {
        // Team management + the roles/permissions matrix admin.
        Route::middleware('permission:staff.manage')->group(function () {
            Route::get('/team', [InternalSettingsController::class, 'team'])->name('team');
            Route::post('/team/invite', [InternalSettingsController::class, 'teamInvite'])->name('team.invite');
            Route::put('/team/{id}/role', [InternalSettingsController::class, 'teamUpdateRole'])->name('team.role');
            Route::delete('/team/{id}', [InternalSettingsController::class, 'teamRemove'])->name('team.remove');

            Route::get('/roles', [InternalRoleController::class, 'index'])->name('roles.index');
            Route::post('/roles', [InternalRoleController::class, 'store'])->name('roles.store');
            Route::put('/roles/{id}', [InternalRoleController::class, 'update'])->whereNumber('id')->name('roles.update');
            Route::delete('/roles/{id}', [InternalRoleController::class, 'destroy'])->whereNumber('id')->name('roles.destroy');
            Route::put('/roles/{id}/permissions', [InternalRoleController::class, 'togglePermission'])->whereNumber('id')->name('roles.permissions');
            Route::put('/roles/{id}/scope', [InternalRoleController::class, 'setScope'])->whereNumber('id')->name('roles.scope');
        });

        // Security — the operator's own password / session page. No matrix
        // capability maps to it, so it stays super_admin-only via EnsureRole
        // (access-identical; the one documented phase-3a exception — not a
        // permission, and not invented as one).
        Route::middleware('role:super_admin')->group(function () {
            Route::get('/security', [InternalSettingsController::class, 'security'])->name('security');
            Route::post('/security/password', [InternalSettingsController::class, 'securityChangePassword'])->name('security.password');
            Route::post('/security/sessions/clear', [InternalSettingsController::class, 'securityClearSessions'])->name('security.sessions.clear');
        });

        Route::middleware('permission:settings.notifications')->group(function () {
            Route::get('/notifications', [InternalSettingsController::class, 'notifications'])->name('notifications');
            Route::post('/notifications', [InternalSettingsController::class, 'notificationsUpdate'])->name('notifications.update');
        });

        // Billing automation — auto-suspension thresholds.
        Route::middleware('permission:settings.billing_automation')->group(function () {
            Route::get('/billing', [InternalSettingsController::class, 'billing'])->name('billing');
            Route::post('/billing', [InternalSettingsController::class, 'billingUpdate'])->name('billing.update');
        });

        // Reminder templates — edit subject/body per escalation tier.
        Route::middleware('permission:settings.reminder_templates')->group(function () {
            Route::get('/reminder-templates', [InternalSettingsController::class, 'reminderTemplates'])->name('reminder-templates');
            Route::put('/reminder-templates/{id}', [InternalSettingsController::class, 'reminderTemplatesUpdate'])
                ->whereNumber('id')
                ->name('reminder-templates.update');
            Route::post('/reminder-templates/{id}/preview', [InternalSettingsController::class, 'reminderTemplatesPreview'])
                ->whereNumber('id')
                ->name('reminder-templates.preview');
        });

        // Integrations (incl. webhook-delivery retry, conceptually).
        Route::middleware('permission:settings.integrations')->group(function () {
            Route::get('/integrations', [InternalSettingsController::class, 'integrations'])->name('integrations');
            Route::get('/integrations/{name}/test', [InternalSettingsController::class, 'integrationTest'])->name('integrations.test');
            Route::post('/integrations/test/email', [InternalSettingsController::class, 'testEmail'])->name('integrations.test-email');
        });

        Route::middleware('permission:settings.audit_log')->group(function () {
            Route::get('/audit-log', [InternalSettingsController::class, 'auditLog'])->name('audit-log');
        });

        Route::middleware('permission:settings.danger')->group(function () {
            Route::get('/danger', [InternalSettingsController::class, 'danger'])->name('danger');
            Route::post('/danger/reset-notifications', [InternalSettingsController::class, 'dangerResetNotifications'])->name('danger.reset-notifications');
        });

        Route::middleware('permission:billing_entities.manage')->group(function () {
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
        });

        // Products + plans + categories + prices + product-suppliers.
        Route::middleware('permission:products.manage')->group(function () {
            Route::get('/products', [InternalProductController::class, 'index'])->name('products.index');
            Route::post('/products', [InternalProductController::class, 'store'])->name('products.store');
            Route::put('/products/{id}', [InternalProductController::class, 'update'])->name('products.update');
            Route::post('/products/{id}/toggle', [InternalProductController::class, 'toggleActive'])->name('products.toggle');
            Route::post('/products/reorder', [InternalProductController::class, 'updateOrder'])->name('products.reorder');
            Route::get('/products/{id}/plans', [InternalProductController::class, 'plans'])->name('products.plans');

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

        // Commission rules — admin front-end to the commission engine.
        Route::middleware('permission:commission.config')->group(function () {
            Route::get('/commission-rules', [InternalCommissionRuleController::class, 'index'])->name('commission-rules.index');
            Route::post('/commission-rules', [InternalCommissionRuleController::class, 'store'])->name('commission-rules.store');
            Route::put('/commission-rules/{id}', [InternalCommissionRuleController::class, 'update'])
                ->whereNumber('id')->name('commission-rules.update');
            Route::post('/commission-rules/{id}/toggle', [InternalCommissionRuleController::class, 'toggleActive'])
                ->whereNumber('id')->name('commission-rules.toggle');
            Route::delete('/commission-rules/{id}', [InternalCommissionRuleController::class, 'destroy'])
                ->whereNumber('id')->name('commission-rules.destroy');
        });

        // Deployment maintenance — write actions throttled; each confirmed + logged.
        Route::middleware('permission:deployment.run')->group(function () {
            Route::get('/deployment', [InternalDeploymentController::class, 'index'])->name('deployment');
            Route::post('/deployment/migrate', [InternalDeploymentController::class, 'migrate'])
                ->middleware('throttle:10,1')->name('deployment.migrate');
            Route::post('/deployment/clear-cache', [InternalDeploymentController::class, 'clearCache'])
                ->middleware('throttle:10,1')->name('deployment.clear-cache');
            Route::post('/deployment/run-both', [InternalDeploymentController::class, 'runBoth'])
                ->middleware('throttle:10,1')->name('deployment.run-both');
            Route::post('/deployment/seed-roles', [InternalDeploymentController::class, 'seedRoles'])
                ->middleware('throttle:10,1')->name('deployment.seed-roles');
        });
    });

    // Maavelus monthly revenue statements — internal-only, super_admin
    // gated. Each statement is an internal record of platform fees +
    // auto-generated referral commissions, not a customer-facing invoice.
    Route::middleware('permission:maavelus.statements')->prefix('maavelus/statements')->name('internal.maavelus-statements.')->group(function () {
        Route::get('/', [InternalMaavelusStatementController::class, 'index'])->name('index');
        Route::post('/', [InternalMaavelusStatementController::class, 'store'])->name('store');
        Route::get('/{id}', [InternalMaavelusStatementController::class, 'show'])->name('show');
        Route::post('/{id}/confirm', [InternalMaavelusStatementController::class, 'confirm'])->name('confirm');
        Route::get('/{id}/download', [InternalMaavelusStatementController::class, 'download'])->name('download');
        Route::delete('/{id}', [InternalMaavelusStatementController::class, 'destroy'])->name('destroy');
    });
});

/*
| Customer -> Company rename (2026-07): the internal customer routes moved
| from /customers* to /companies*. Old links already in the wild — emailed
| "view company" links, bookmarks — are GET requests, so 301 those to the
| new paths. The catch-all only matches /customers/<something>; it can never
| shadow /customer-groups or /customer-products (no "/customers/" prefix) or
| the live /companies routes (different segment).
*/
Route::redirect('/customers', '/companies', 301);
Route::get('/customers/{path}', fn (string $path) => redirect('/companies/'.$path, 301))
    ->where('path', '.*');

/*
|--------------------------------------------------------------------------
| Public proposal acceptance — NO auth
|--------------------------------------------------------------------------
| Token-only authorisation. The token is single-use: accept() nulls it
| out, so a re-visit shows the success page rather than the form again.
*/
// Throttled like the other public token surfaces: the GET (view + the
// only brute-probe surface) at the public-GET tier used by /r/{code} and
// form embeds; the POST (the acceptance write) at the tighter token-submit
// tier the portal password-reset flow uses. The token is 256-bit so this is
// hygiene/DoS bounding, not the token's primary defence.
Route::get('/proposals/accept/{token}', [PublicProposalAcceptanceController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:30,1')
    ->name('proposal.accept.show');
Route::post('/proposals/accept/{token}', [PublicProposalAcceptanceController::class, 'accept'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:6,1')
    ->name('proposal.accept.submit');

// Reject mirrors accept: same token (the acceptance link doubles as the reject
// link), same 64-hex constraint, same throttle tiers (GET 30/min, POST 6/min).
Route::get('/proposals/reject/{token}', [PublicProposalRejectionController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:30,1')
    ->name('proposal.reject.show');
Route::post('/proposals/reject/{token}', [PublicProposalRejectionController::class, 'reject'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:6,1')
    ->name('proposal.reject.submit');

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
// Public embeddable forms — embed.js + submit share ONE open,
// credential-less CORS policy (forms.cors) so any site can embed them.
// (The credentialed global config/cors.php can't use '*'.)
Route::middleware('forms.cors')->group(function () {
    Route::get('/forms/{slug}/embed.js', [PublicEmbedController::class, 'script'])
        ->where('slug', '[a-z0-9-]+')
        ->name('form.embed.script');

    Route::post('/forms/{slug}/submit', [PublicFormController::class, 'submit'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware('throttle:30,1')
        ->name('form.submit');

    // Multi-step draft persistence + resume (anonymous). draft/init creates the
    // row and returns the draft_token the embed widget stores in localStorage;
    // save/submit are keyed on that token. CSRF-excluded via forms/*/draft* in
    // bootstrap/app.php (same posture as /submit).
    Route::post('/forms/{slug}/draft', [FormDraftController::class, 'init'])
        ->where('slug', '[a-z0-9-]+')
        ->name('form.draft.init');
    Route::put('/forms/{slug}/draft/{token}', [FormDraftController::class, 'saveStep'])
        ->where('slug', '[a-z0-9-]+')
        ->name('form.draft.save');
    Route::post('/forms/{slug}/draft/{token}/submit', [FormDraftController::class, 'submit'])
        ->where('slug', '[a-z0-9-]+')
        ->name('form.draft.submit');

    // CORS preflight for the cross-origin submit POST. forms.cors answers
    // OPTIONS directly; this route just makes the request matchable so the
    // middleware runs instead of the router 405-ing an unknown method.
    Route::options('/forms/{slug}/submit', fn () => response('', 204))
        ->where('slug', '[a-z0-9-]+')
        ->name('form.submit.preflight');

    // Preflight for the cross-origin draft endpoints. The draft-save is a PUT
    // (non-simple), so the browser preflights it; without a matchable OPTIONS
    // route the router would 405 before forms.cors could answer. Mirrors the
    // submit preflight above.
    Route::options('/forms/{slug}/draft', fn () => response('', 204))
        ->where('slug', '[a-z0-9-]+')
        ->name('form.draft.preflight');
    Route::options('/forms/{slug}/draft/{token}', fn () => response('', 204))
        ->where('slug', '[a-z0-9-]+')
        ->name('form.draft.save.preflight');
    Route::options('/forms/{slug}/draft/{token}/submit', fn () => response('', 204))
        ->where('slug', '[a-z0-9-]+')
        ->name('form.draft.submit.preflight');
});

/*
|--------------------------------------------------------------------------
| Public Plans widget — NO auth, CSRF excluded in bootstrap/app.php
|--------------------------------------------------------------------------
| Same embed mechanism + open CORS posture as the forms widget
| (PLANS-WIDGET-DESIGN.md §3). {slug} is products.slug:
|   GET  /plans/{slug}/embed.js   — JavaScript pricing widget for any site
|   GET  /plan/{id}/embed.js      — SINGLE-plan widget (one card, all of
|                                   that plan's prices). Singular /plan/
|                                   prefix so a numeric id can't be
|                                   swallowed by the {slug} regex above.
|   POST /plans/{slug}/checkout   — anonymous checkout init (honeypot +
|                                   per-IP rate limit + Turnstile); returns
|                                   an embedded Stripe Checkout secret.
|                                   NO DB writes — provisioning is
|                                   webhook-only (/webhooks/stripe).
|                                   Serves BOTH embed flavours.
|   GET  /plans/{slug}/purchased  — post-payment landing (presentation only)
*/
Route::middleware('forms.cors')->group(function () {
    Route::get('/plans/{slug}/embed.js', [PublicPlanEmbedController::class, 'script'])
        ->where('slug', '[a-z0-9-]+')
        ->name('plan.embed.script');

    Route::get('/plan/{plan}/embed.js', [PublicPlanEmbedController::class, 'planScript'])
        ->whereNumber('plan')
        ->name('plan.single.embed.script');

    Route::post('/plans/{slug}/checkout', [PublicPlanCheckoutController::class, 'create'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware('throttle:30,1')
        ->name('plan.checkout');

    // Preflight for the cross-origin checkout POST — same rationale as the
    // form-submit preflight above.
    Route::options('/plans/{slug}/checkout', fn () => response('', 204))
        ->where('slug', '[a-z0-9-]+')
        ->name('plan.checkout.preflight');
});

// The embedded-Checkout return leg lands here (first-party page, so no
// CORS group needed). Throttled like the other public GET surfaces.
Route::get('/plans/{slug}/purchased', [PublicPlanCheckoutController::class, 'purchased'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware('throttle:30,1')
    ->name('plan.purchased');

// Canonical referral hub link. Public + throttled (writes a click row
// per hit). {code} is constrained to 8 alphanumerics; the controller
// normalises to the Crockford alphabet and validates against referrers.
Route::get('/r/{code}', PublicReferralRedirectController::class)
    ->where('code', '[0-9A-Za-z]{8}')
    ->middleware('throttle:30,1')
    ->name('referral.redirect');
// Stripe payment webhooks. MUST precede the /webhooks/{slug} catch-all
// below — the slug regex would otherwise swallow "stripe". Signature is
// verified in VerifyStripeWebhook (fail-closed); idempotency + handling
// live in the controller. CSRF-exempt via the webhooks/* rule in
// bootstrap/app.php (the explicit withoutMiddleware is belt-and-braces).
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'receive'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware([VerifyStripeWebhook::class, 'throttle:120,1'])
    ->name('webhooks.stripe');

Route::post('/webhooks/{slug}', [PublicWebhookController::class, 'receive'])
    ->where('slug', '[a-z0-9-]+')
    ->middleware(['form.webhook', 'throttle:120,1'])
    ->name('form.webhook.receive');

// Postmark inbound email → support ticket. Two-path segment so it never
// collides with the single-segment {slug} webhook above. Auth is the
// shared inbound secret (checked in the controller); CSRF-exempt via the
// webhooks/* rule in bootstrap/app.php.
Route::post('/webhooks/email/inbound', [PublicInboundEmailController::class, 'receive'])
    ->middleware('throttle:120,1')
    ->name('email.inbound');

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
    Route::post('/login', [PortalAuthController::class, 'login'])
        ->middleware('throttle:portal-login')
        ->name('portal.login.submit');

    // Password reset is part of the guest surface — once logged in,
    // a portal user changes their password from /portal/account.
    // Throttled so neither the reset-link request nor the token-submit
    // (Hash::check) path can be hammered.
    Route::get('/forgot-password', [PortalPasswordController::class, 'showForgotForm'])->name('portal.forgot-password');
    Route::post('/forgot-password', [PortalPasswordController::class, 'sendResetLink'])
        ->middleware('throttle:6,1')
        ->name('portal.forgot-password.submit');
    Route::get('/reset-password', [PortalPasswordController::class, 'showResetForm'])->name('portal.reset-password');
    Route::post('/reset-password', [PortalPasswordController::class, 'resetPassword'])
        ->middleware('throttle:6,1')
        ->name('portal.reset-password.submit');
});

// Preview entrypoint sits outside portal_guest because the staff
// member who launched the preview might already hold a portal session
// from an earlier visit — the controller swaps the active user.
Route::get('/portal/preview', [PortalAuthController::class, 'preview'])->name('portal.preview');

// Exit a portal preview — drops the portal-guard session and returns
// the operator to Powerhouse. Outside auth.portal: the handler clears
// the guard itself and must stay reachable as the session unwinds.
Route::get('/portal/preview/exit', [PortalAuthController::class, 'exitPreview'])->name('portal.preview.exit');

// Authenticated portal area. auth.portal alias maps to EnsurePortalUser —
// every controller here can rely on Auth::guard('portal')->user() being set.
Route::prefix('portal')->middleware('auth.portal')->group(function () {
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('portal.logout');

    Route::get('/dashboard', PortalDashboardController::class)->name('portal.dashboard');

    // Read-only asset overview (Stage 4): the customer's websites, domains and
    // services, scoped to their own customer_id. No mutations here.
    Route::get('/assets', [PortalAssetController::class, 'index'])->name('portal.assets.index');

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

    // Security page merged into /account — password + connected apps now
    // live there. Old URL kept alive with a permanent redirect.
    Route::permanentRedirect('/security', '/portal/account')->name('portal.security');

    Route::get('/subscriptions', [PortalSubscriptionController::class, 'index'])->name('portal.subscriptions.index');
    Route::post('/subscriptions', [PortalSubscriptionController::class, 'store'])->name('portal.subscriptions.store');
    Route::post('/subscriptions/{id}/cancel', [PortalSubscriptionController::class, 'cancel'])->name('portal.subscriptions.cancel');

    Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('portal.invoices.index');
    Route::get('/invoices/{id}', [PortalInvoiceController::class, 'show'])->whereNumber('id')->name('portal.invoices.show');
    Route::get('/invoices/{id}/checkout', [PortalInvoiceController::class, 'checkoutSession'])->whereNumber('id')->name('portal.invoices.checkout');
    Route::get('/invoices/{id}/paid', [PortalInvoiceController::class, 'paid'])->whereNumber('id')->name('portal.invoices.paid');
    Route::get('/invoices/{id}/pdf', [PortalInvoiceController::class, 'downloadPdf'])->name('portal.invoices.pdf');
    Route::get('/invoices/{id}/preview-pdf', [PortalInvoiceController::class, 'previewPdf'])->name('portal.invoices.preview-pdf');

    // Multi-step form respondent flow. Drafts resume by portal_user_id (no
    // token), scoped to the authenticated portal user in the controller.
    Route::get('/forms/{id}', [PortalFormController::class, 'show'])->name('portal.forms.show')->whereNumber('id');
    Route::post('/forms/{id}/draft', [PortalFormController::class, 'initDraft'])->name('portal.forms.draft.init')->whereNumber('id');
    Route::put('/forms/{id}/draft', [PortalFormController::class, 'saveStep'])->name('portal.forms.draft.save')->whereNumber('id');
    Route::post('/forms/{id}/draft/submit', [PortalFormController::class, 'submit'])->name('portal.forms.draft.submit')->whereNumber('id');

    Route::get('/support', [PortalSupportController::class, 'index'])->name('portal.support.index');
    Route::post('/support', [PortalSupportController::class, 'store'])->name('portal.support.store');
    Route::get('/support/{id}', [PortalSupportController::class, 'show'])->name('portal.support.show');
    Route::post('/support/{id}/reply', [PortalSupportController::class, 'reply'])->name('portal.support.reply');

    // Saved payment methods (Billing P1) — manage cards; NO charging here.
    Route::get('/payment-methods', [PortalPaymentMethodController::class, 'index'])->name('portal.payment-methods.index');
    Route::post('/payment-methods/setup-intent', [PortalPaymentMethodController::class, 'setupIntent'])->name('portal.payment-methods.setup-intent');
    Route::post('/payment-methods', [PortalPaymentMethodController::class, 'store'])->name('portal.payment-methods.store');
    Route::post('/payment-methods/{id}/default', [PortalPaymentMethodController::class, 'setDefault'])->whereNumber('id')->name('portal.payment-methods.default');
    Route::delete('/payment-methods/{id}', [PortalPaymentMethodController::class, 'destroy'])->whereNumber('id')->name('portal.payment-methods.destroy');

    Route::get('/account', [PortalAccountController::class, 'index'])->name('portal.account.index');
    Route::put('/account', [PortalAccountController::class, 'update'])->name('portal.account.update');
    Route::put('/account/password', [PortalAccountController::class, 'updatePassword'])->name('portal.account.password');
    Route::delete('/account/tokens/{token}', [PortalAccountController::class, 'revokeToken'])
        ->where('token', '[a-zA-Z0-9]+')
        ->name('portal.account.tokens.revoke');
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
        Route::get('/companies', [ReferrerCustomerController::class, 'index'])->name('customers');

        // Deal registration — register → (staff review) → 90-day protection.
        Route::get('/referrals', [ReferralDealController::class, 'index'])->name('referrals');
        Route::post('/referrals', [ReferralDealController::class, 'store'])->name('referrals.store');

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

// Exit a referrer preview — restores the originating super_admin's
// web-guard session (referrers share that guard, so entering preview
// replaced it). No role middleware: the request arrives as the
// referrer and the handler swaps it back to the admin.
Route::get('/referrer/preview/exit', [ReferrerAuthController::class, 'exitPreview'])->name('referrer.preview.exit');

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

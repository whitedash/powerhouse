<?php

namespace App\Support;

use App\Enums\ScopeArea;

/**
 * The display catalogue for the roles & permissions admin matrix.
 *
 * Mirrors the approved mock (design/Roles & Permissions.html): the grouping
 * is DESIGNED (functional areas), not a naive prefix split. This is the
 * single source of truth the controller hands to the Vue page so the grid
 * renders exactly as approved.
 *
 * Each group: key, label, icon (tabler name), scoped (bool), rows[].
 * Each row: key, label (chip), desc, type (access|manage|scope|compose|
 * danger|pii). Scope rows additionally carry `scope: true` and `area`
 * (an App\Enums\ScopeArea value) — they persist to role_scopes, not to the
 * boolean permissions table.
 *
 * NOTE: this catalogue drives DISPLAY only. The set of grantable boolean
 * permissions is validated at write time against the actual registered
 * `permissions` rows (guard web), never against this list — so an arbitrary
 * string can never be granted even if the catalogue drifted.
 */
class PermissionMatrix
{
    /**
     * @return list<array{key: string, label: string, icon: string, scoped: bool, rows: list<array<string, mixed>>}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'customers', 'label' => 'Customers & CRM', 'icon' => 'users', 'scoped' => false, 'rows' => [
                self::row('customers.access', 'Access', 'access', 'Enter and view customers, contacts, contracts and customer groups.'),
                self::row('customers.manage', 'Manage', 'manage', 'Create/edit customers, contacts, contracts, groups & notes; portal invites; enable/suspend product subs; auto-collect.'),
                self::row('customers.referral.manage', 'Danger', 'danger', 'Attach or detach a referral on a customer (voids pending commissions).'),
                self::row('customers.exemption', 'Danger', 'danger', "Toggle a customer's auto-suspension exemption."),
                self::row('gdpr.export', 'PII', 'pii', "Export a customer's full personal data (GDPR Art. 20)."),
                self::row('gdpr.erase', 'Danger', 'danger', 'Erase a customer under right-to-erasure (GDPR Art. 17 — irreversible).'),
            ]],
            ['key' => 'people', 'label' => 'People', 'icon' => 'user-circle', 'scoped' => false, 'rows' => [
                self::row('people.access', 'Access', 'access', 'Enter and view the cross-company People directory.'),
                self::row('people.manage', 'Manage', 'manage', 'Create/edit people and attach/detach them from companies.'),
                self::row('people.delete', 'Danger', 'danger', 'Hard-delete a person identity.'),
            ]],
            ['key' => 'invoices', 'label' => 'Invoices & billing', 'icon' => 'receipt', 'scoped' => false, 'rows' => [
                self::row('invoices.access', 'Access', 'access', 'Enter and view invoices and their PDFs.'),
                self::row('invoices.manage', 'Manage', 'manage', 'Create/edit, send, record payments, payment links, reminders, stop recurring.'),
                self::row('invoices.void', 'Danger', 'danger', 'Void an issued invoice.'),
            ]],
            ['key' => 'leads', 'label' => 'Leads (sales pipeline)', 'icon' => 'filter', 'scoped' => true, 'rows' => [
                self::scopeRow('leads', 'Section access scope. Assigned = leads assigned to me.'),
                self::row('leads.manage', 'Manage', 'manage', 'Pipeline CRUD, status, convert to customer, approve/reject deal registrations — on the leads in scope.'),
            ]],
            ['key' => 'proposals', 'label' => 'Proposals', 'icon' => 'file-description', 'scoped' => false, 'rows' => [
                self::row('proposals.access', 'Access', 'access', 'Enter and view proposals and payment schedules.'),
                self::row('proposals.manage', 'Manage', 'manage', 'Proposals CRUD, send, convert to contract; payment schedules.'),
            ]],
            ['key' => 'projects', 'label' => 'Projects (delivery)', 'icon' => 'briefcase', 'scoped' => true, 'rows' => [
                self::scopeRow('projects', "Section access scope. Assigned = I'm a member of the project (lead, member or viewer)."),
                self::row('projects.manage', 'Manage', 'manage', 'Projects, milestones, time entries and files CRUD; generate invoices — on the projects in scope.'),
            ]],
            ['key' => 'tasks', 'label' => 'Tasks & activities', 'icon' => 'checkbox', 'scoped' => true, 'rows' => [
                self::scopeRow('tasks', 'Section access scope. Assigned = tasks strictly assigned to me (the assignee — not ones I created and delegated).'),
                self::row('tasks.manage', 'Manage', 'manage', 'Create/edit activities, status, reschedule, complete, pin, attachments, task notes — on the tasks in scope.'),
            ]],
            ['key' => 'support', 'label' => 'Support / helpdesk', 'icon' => 'headset', 'scoped' => true, 'rows' => [
                self::scopeRow('support', 'Section access scope. Assigned = tickets assigned to me.'),
                self::row('support.view_unassigned', 'Composes', 'compose', 'Can also see unassigned / untriaged tickets. Composes with the scope (Assigned + this = own tickets plus the intake pool). Redundant when scope = All.'),
                self::row('support.manage', 'Manage', 'manage', 'Reply to tickets, change status, spin CRM tasks off a ticket — on the tickets in scope.'),
            ]],
            ['key' => 'forms', 'label' => 'Forms', 'icon' => 'forms', 'scoped' => false, 'rows' => [
                self::row('forms.access', 'Access', 'access', 'Enter and view the form builder and themes.'),
                self::row('forms.manage', 'Manage', 'manage', 'Build forms and manage themes (excludes raw CSS and reading submissions).'),
                self::row('forms.view_submissions', 'PII', 'pii', 'Read submissions captured through public forms (personal data).'),
                self::row('forms.custom_css', 'Danger', 'danger', "Edit the raw custom CSS injected into the embed widget's shadow root."),
            ]],
            ['key' => 'hosting', 'label' => 'Hosting (domains & websites)', 'icon' => 'world', 'scoped' => false, 'rows' => [
                self::row('hosting.access', 'Access', 'access', 'Enter and view domains and websites.'),
                self::row('hosting.manage', 'Manage', 'manage', 'Domains & websites CRUD, WHOIS, DNS, hosting sync/suspend/reinstate, PageSpeed.'),
                self::row('wordpress.bulk_update', 'Danger', 'danger', 'Run bulk WordPress core/plugin/theme updates across live customer sites.'),
            ]],
            ['key' => 'expenses', 'label' => 'Expenses & suppliers', 'icon' => 'cash', 'scoped' => false, 'rows' => [
                self::row('expenses.access', 'Access', 'access', 'Enter and view expenses and the supplier register.'),
                self::row('expenses.manage', 'Manage', 'manage', 'Expenses & suppliers CRUD, mark expenses paid, attach receipts.'),
                self::row('expenses.approve', 'Danger', 'danger', 'Approve an expense — moves it from raised to spendable.'),
            ]],
            ['key' => 'workflows', 'label' => 'Workflows (automation)', 'icon' => 'route', 'scoped' => false, 'rows' => [
                self::row('workflows.access', 'Access', 'access', 'Enter and view automation workflows.'),
                self::row('workflows.manage', 'Manage', 'manage', 'Create/edit automation rules and enable/disable them.'),
            ]],
            ['key' => 'knowledge_base', 'label' => 'Knowledge base', 'icon' => 'book', 'scoped' => false, 'rows' => [
                self::row('knowledge_base.access', 'Access', 'access', 'Enter and view internal help & docs articles.'),
                self::row('knowledge_base.manage', 'Manage', 'manage', 'Create/edit/delete help & docs articles.'),
            ]],
            ['key' => 'provisioning', 'label' => 'Provisioning & subscriptions', 'icon' => 'layout-grid', 'scoped' => false, 'rows' => [
                self::row('provisioning.access', 'Access', 'access', 'Enter and view provisioning and subscription records.'),
                self::row('provisioning.manage', 'Manage', 'manage', 'Toggle provisioning; update and cancel subscription records.'),
            ]],
            ['key' => 'analytics', 'label' => 'Analytics & reporting', 'icon' => 'chart', 'scoped' => false, 'rows' => [
                self::row('analytics.access', 'Access', 'access', 'Enter and view the analytics dashboards.'),
            ]],
            ['key' => 'referrers', 'label' => 'Referrers & commissions', 'icon' => 'users-group', 'scoped' => false, 'rows' => [
                self::row('referrers.access', 'Access', 'access', 'View referrers and the referral attribution ledger.'),
                self::row('referrers.manage', 'Danger', 'danger', 'Create/edit referrers and reset their passwords.'),
                self::row('commission.approve', 'Danger', 'danger', 'Approve commission entries and mark referrer payouts as paid.'),
                self::row('commission.config', 'Danger', 'danger', 'Manage the commission rules that drive payout calculations.'),
            ]],
            ['key' => 'maavelus', 'label' => 'Maavelus statements', 'icon' => 'tools', 'scoped' => false, 'rows' => [
                self::row('maavelus.statements', 'Danger', 'danger', 'Internal monthly platform-fee & commission statements — create, confirm, download, delete.'),
            ]],
            ['key' => 'platform', 'label' => 'Platform administration', 'icon' => 'settings', 'scoped' => false, 'rows' => [
                self::row('settings.access', 'Access', 'access', 'See the Settings overview shell.'),
                self::row('staff.manage', 'Danger', 'danger', 'Invite staff, change roles, remove members — and manage roles & this permission matrix.'),
                self::row('impersonate', 'Danger', 'danger', 'Preview the app as a customer (portal) or referrer.'),
                self::row('billing_entities.manage', 'Danger', 'danger', 'Manage billing entities — the legal entities invoices are issued from.'),
                self::row('products.manage', 'Danger', 'danger', 'Manage products, plans, plan categories, prices and product suppliers.'),
                self::row('settings.integrations', 'Danger', 'danger', 'Integration credentials & connection tests. Includes webhook-delivery retry.'),
                self::row('settings.notifications', 'Danger', 'danger', 'Workspace notification & support-automation settings.'),
                self::row('settings.billing_automation', 'Danger', 'danger', 'Auto-suspension thresholds & billing automation behaviour.'),
                self::row('settings.reminder_templates', 'Danger', 'danger', 'Edit invoice reminder email templates.'),
                self::row('settings.audit_log', 'Danger', 'danger', 'View the platform-wide audit log.'),
                self::row('settings.danger', 'Danger', 'danger', 'The Danger Zone — destructive settings resets.'),
                self::row('deployment.run', 'Danger', 'danger', 'Run database migrations and clear caches from the deployment panel.'),
            ]],
        ];
    }

    /**
     * Every boolean permission key in the catalogue (i.e. excluding the four
     * scope rows). Used for cross-checks/tests; write validation still hits
     * the live permissions table.
     *
     * @return list<string>
     */
    public static function booleanPermissionKeys(): array
    {
        $keys = [];
        foreach (self::groups() as $group) {
            foreach ($group['rows'] as $row) {
                if (empty($row['scope'])) {
                    $keys[] = $row['key'];
                }
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $key, string $label, string $type, string $desc): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'desc' => $desc, 'scope' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private static function scopeRow(string $area, string $desc): array
    {
        return [
            'key' => "{$area}.access",
            'label' => 'Scope',
            'type' => 'scope',
            'desc' => $desc,
            'scope' => true,
            'area' => ScopeArea::from($area)->value,
        ];
    }
}

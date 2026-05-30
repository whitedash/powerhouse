<?php

namespace Database\Seeders;

use App\Models\ReminderTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds the five tier-keyed reminder templates with sensible defaults
 * so the system has working copy out of the box. Idempotent — uses
 * updateOrCreate keyed on tier, so re-running the seeder will reset
 * only the bodies of templates that haven't been hand-edited yet
 * (the operator's edits to the row stay because tier is the key but
 * the seeder will overwrite their subject/body if re-run).
 *
 * To safely re-run after operators have customised templates, prefer
 * `firstOrCreate(['tier' => …], $defaults)` semantics — which the
 * controller wires up via "Reset to default" rather than seeding.
 */
class ReminderTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            ReminderTemplate::updateOrCreate(
                ['tier' => $template['tier']],
                $template,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templates(): array
    {
        return [
            [
                'tier' => 'due_soon',
                'name' => 'Due soon',
                'tone' => 'friendly',
                'is_active' => true,
                'subject' => 'Reminder: Invoice {{invoice_number}} due in {{days_until_due}} days',
                'body' => <<<'BODY'
                    Hi {{contact_name}},

                    Just a friendly reminder that invoice {{invoice_number}} for {{invoice_amount}} is due on {{due_date}}.

                    Payment details:
                    Bank: {{bank_name}}
                    Account: {{account_number}}
                    Sort code: {{sort_code}}
                    Reference: {{payment_ref}}

                    You can also pay via your portal: {{portal_url}}

                    If you have any questions, please don't hesitate to get in touch.

                    Thank you for your business.

                    — {{company_name}}
                    BODY,
                'variables_used' => ['contact_name', 'invoice_number', 'invoice_amount', 'due_date', 'days_until_due', 'bank_name', 'account_number', 'sort_code', 'payment_ref', 'portal_url', 'company_name'],
            ],
            [
                'tier' => 'due_today',
                'name' => 'Due today',
                'tone' => 'friendly',
                'is_active' => true,
                'subject' => 'Invoice {{invoice_number}} is due today — {{invoice_amount}}',
                'body' => <<<'BODY'
                    Hi {{contact_name}},

                    A heads-up that invoice {{invoice_number}} for {{invoice_amount}} is due today, {{due_date}}.

                    Payment details:
                    Bank: {{bank_name}}
                    Account: {{account_number}}
                    Sort code: {{sort_code}}
                    Reference: {{payment_ref}}

                    Pay via your portal: {{portal_url}}

                    If you've already paid, please disregard — bank transfers can take a day to clear our side.

                    Thank you for your business.

                    — {{company_name}}
                    BODY,
                'variables_used' => ['contact_name', 'invoice_number', 'invoice_amount', 'due_date', 'bank_name', 'account_number', 'sort_code', 'payment_ref', 'portal_url', 'company_name'],
            ],
            [
                'tier' => 'first_reminder',
                'name' => 'First reminder',
                'tone' => 'firm',
                'is_active' => true,
                'subject' => 'Invoice {{invoice_number}} is now overdue — action required',
                'body' => <<<'BODY'
                    Hi {{contact_name}},

                    Invoice {{invoice_number}} for {{invoice_amount}} was due on {{due_date}} and is now {{days_overdue}} days overdue. Please arrange payment at your earliest convenience.

                    Payment details:
                    Bank: {{bank_name}}
                    Account: {{account_number}}
                    Sort code: {{sort_code}}
                    Reference: {{payment_ref}}

                    You can also pay via your portal: {{portal_url}}

                    If payment has already been made please let us know so we can reconcile our records.

                    — {{company_name}}
                    BODY,
                'variables_used' => ['contact_name', 'invoice_number', 'invoice_amount', 'due_date', 'days_overdue', 'bank_name', 'account_number', 'sort_code', 'payment_ref', 'portal_url', 'company_name'],
            ],
            [
                'tier' => 'second_reminder',
                'name' => 'Second reminder',
                'tone' => 'urgent',
                'is_active' => true,
                'subject' => 'Second reminder: Invoice {{invoice_number}} — {{days_overdue}} days overdue',
                'body' => <<<'BODY'
                    Hi {{contact_name}},

                    Despite our previous reminder, invoice {{invoice_number}} for {{invoice_amount}} remains unpaid. The invoice is now {{days_overdue}} days overdue.

                    Please settle this without further delay to avoid disruption to your account.

                    Payment details:
                    Bank: {{bank_name}}
                    Account: {{account_number}}
                    Sort code: {{sort_code}}
                    Reference: {{payment_ref}}

                    Pay via your portal: {{portal_url}}

                    If there are any difficulties with payment, please contact us today so we can find a resolution.

                    — {{company_name}}
                    BODY,
                'variables_used' => ['contact_name', 'invoice_number', 'invoice_amount', 'days_overdue', 'bank_name', 'account_number', 'sort_code', 'payment_ref', 'portal_url', 'company_name'],
            ],
            [
                'tier' => 'final_notice',
                'name' => 'Final notice',
                'tone' => 'final',
                'is_active' => true,
                'subject' => 'FINAL NOTICE: Invoice {{invoice_number}} — immediate payment required',
                'body' => <<<'BODY'
                    Hi {{contact_name}},

                    This is the final notice for invoice {{invoice_number}} for {{invoice_amount}}, originally due on {{due_date}} and now {{days_overdue}} days overdue.

                    If payment is not received within 7 days, your account may be suspended and any active services interrupted. The matter may also be escalated to debt recovery.

                    Payment details:
                    Bank: {{bank_name}}
                    Account: {{account_number}}
                    Sort code: {{sort_code}}
                    Reference: {{payment_ref}}

                    Pay via your portal: {{portal_url}}

                    Please contact us immediately if you believe this notice has been issued in error.

                    — {{company_name}}
                    BODY,
                'variables_used' => ['contact_name', 'invoice_number', 'invoice_amount', 'due_date', 'days_overdue', 'bank_name', 'account_number', 'sort_code', 'payment_ref', 'portal_url', 'company_name'],
            ],
        ];
    }
}

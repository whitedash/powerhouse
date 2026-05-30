<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ReminderTemplate;

/**
 * Renders an invoice reminder template — picks the right ReminderTemplate
 * row for the given tier, substitutes every {{placeholder}} with a real
 * value drawn from the invoice + customer + billing entity, and returns
 * the rendered subject + body for the email layer to send.
 *
 * Lives as a service rather than a model method because the renderer
 * touches the encrypted bank-detail fields and we want one place to
 * deal with that decryption + fallback safely.
 */
class ReminderTemplateService
{
    /**
     * Map of tier → template row. Templates are static once loaded so
     * a sweep that touches 100 invoices doesn't rerun this query for
     * each one.
     *
     * @var array<string, ReminderTemplate|null>
     */
    private array $cache = [];

    public function getTemplateForTier(string $tier): ?ReminderTemplate
    {
        if (! array_key_exists($tier, $this->cache)) {
            $this->cache[$tier] = ReminderTemplate::where('tier', $tier)
                ->where('is_active', true)
                ->first();
        }

        return $this->cache[$tier];
    }

    /**
     * Render the template against a specific invoice.
     *
     * Returns:
     *   - subject: rendered email subject line
     *   - body: rendered email body
     *   - variables: the full substitution map used
     *
     * @return array{subject: string, body: string, variables: array<string, string>}
     */
    public function renderTemplate(ReminderTemplate $template, Invoice $invoice): array
    {
        // Eager-load the chain the renderer needs. Idempotent — if the
        // caller already loaded these the load() is a no-op.
        $invoice->loadMissing([
            'customer.primaryContact',
            'billingEntity',
        ]);

        $variables = $this->buildVariables($invoice);

        return [
            'subject' => $this->replaceVars($template->subject, $variables),
            'body' => $this->replaceVars($template->body, $variables),
            'variables' => $variables,
        ];
    }

    /**
     * Build the {{placeholder}} → value map for an invoice. Public so
     * the management UI's preview endpoint can show the operator what
     * placeholders resolve to without round-tripping through the
     * renderer.
     *
     * @return array<string, string>
     */
    public function buildVariables(Invoice $invoice): array
    {
        // diffInDays() returns a non-negative absolute difference in
        // Carbon 3; flip the sign manually based on whether the due
        // date is in the past or future.
        $today = now()->startOfDay();
        $daysOverdue = 0;
        $daysUntilDue = 0;
        if ($invoice->due_date) {
            $due = $invoice->due_date->copy()->startOfDay();
            // Carbon 3's diffInDays() can return a signed float depending
            // on the call site — abs() keeps the rendered "X days
            // overdue" text reading naturally regardless.
            if ($due->isPast()) {
                $daysOverdue = (int) abs($today->diffInDays($due));
            } elseif ($due->isFuture()) {
                $daysUntilDue = (int) abs($today->diffInDays($due));
            }
        }

        // customer is NOT NULL on invoices.customer_id, so the relation
        // is non-null after eager-load — phpstan refuses the nullsafe.
        // primaryContact is genuinely optional.
        $customer = $invoice->customer;
        $contact = $customer?->primaryContact;
        $be = $invoice->billingEntity;

        // BillingEntity bank fields can hold an empty string; cast to
        // (string) at the use site to flatten any nulls from the cast.
        $bankName = $be !== null ? (string) $be->bank_name : '';
        $accountNumber = $be !== null ? (string) $be->account_number : '';
        $sortCode = $be !== null ? (string) $be->sort_code : '';
        $companyName = $be !== null ? (string) ($be->legal_name !== null ? $be->legal_name : $be->name) : '';

        return [
            'customer_name' => $customer !== null ? $customer->name : '',
            // Fall back to the customer name when no primary contact
            // is attached — beats addressing the email to "" / "Dear,".
            'contact_name' => $contact !== null
                ? $contact->name
                : ($customer !== null ? $customer->name : 'there'),
            'invoice_number' => (string) $invoice->number,
            'invoice_amount' => '£'.number_format((float) $invoice->total, 2),
            'due_date' => $invoice->due_date?->format('d M Y') ?? '',
            'days_overdue' => (string) $daysOverdue,
            'days_until_due' => (string) $daysUntilDue,
            'payment_ref' => (string) $invoice->number,
            // Bank details are cast as encrypted on BillingEntity —
            // accessing them returns plaintext automatically.
            'bank_name' => $bankName,
            'account_number' => $accountNumber,
            'sort_code' => $sortCode,
            'company_name' => $companyName,
            'portal_url' => rtrim((string) config('app.url'), '/').'/portal',
        ];
    }

    /**
     * Replace every {{variable}} in the given text. Unknown variables
     * are left intact so the operator can spot typos in the rendered
     * preview rather than silently substituting an empty string.
     *
     * @param  array<string, string>  $vars
     */
    private function replaceVars(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace('{{'.$key.'}}', $value, $text);
        }

        return $text;
    }
}

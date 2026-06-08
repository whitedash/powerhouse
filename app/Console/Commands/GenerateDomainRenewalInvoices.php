<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\BillingEntity;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ProductPlan;
use App\Models\User;
use App\Support\InvoiceVat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Expiry-triggered domain renewal billing.
 *
 * Unlike subscriptions (billed by next_billing_date), a domain is billed
 * off its registrar EXPIRY DATE: 14 days before expiry, if it auto-renews
 * and has a pricing plan, we raise a draft renewal invoice. Domains are NOT
 * CustomerProducts, so invoices:generate-subscriptions never touches them.
 *
 * Pricing reuses the product/plan system (like hosting): a domain plan has
 * exactly ONE active price tier whose interval IS the renewal duration and
 * whose price IS the renewal price; the invoice is raised under the plan's
 * product billing entity.
 *
 * Idempotency: we stamp domains.renewal_invoiced_for with the expiry_date a
 * renewal was raised for, and only bill when the CURRENT expiry_date differs.
 * So each expiry cycle is billed exactly once; the next cycle becomes
 * billable when a registrar sync advances expiry_date.
 */
class GenerateDomainRenewalInvoices extends Command
{
    /** @var string */
    protected $signature = 'invoices:generate-domain-renewals '
        .'{--days=14 : How many days before expiry to raise the renewal invoice}'
        .'{--dry-run : List which domains would be invoiced without creating any rows}';

    /** @var string */
    protected $description = 'Raise draft renewal invoices for auto-renewing domains within the expiry window.';

    private const DEFAULT_PAYMENT_TERM_DAYS = 14;

    /** @var array<int, BillingEntity|null> Per-run cache of entities for VAT. */
    private array $vatEntities = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = max(1, (int) $this->option('days'));
        $today = Carbon::today();
        $window = $today->copy()->addDays($days);

        // Eligible: auto-renewing, has a TLD, expiring within the window,
        // and not yet invoiced for THIS expiry cycle.
        $domains = Domain::query()
            ->where('auto_renew', true)
            ->whereNotNull('tld')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$today->toDateString(), $window->toDateString()])
            ->where(function ($q): void {
                $q->whereNull('renewal_invoiced_for')
                    ->orWhereColumn('renewal_invoiced_for', '!=', 'expiry_date');
            })
            ->with('customer:id,name')
            ->get();

        if ($domains->isEmpty()) {
            $this->info('No domains due for renewal invoicing.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d domain(s) within the %d-day renewal window.', $domains->count(), $days));

        // Resolve every active domain plan once, keyed by (lowercased) TLD —
        // the authoritative TLD → plan match. One active plan per TLD is
        // enforced at the plan form, so this map is unambiguous.
        $plansByTld = ProductPlan::where('is_domain', true)
            ->where('is_active', true)
            ->whereNotNull('tld')
            ->with([
                'product:id,name,billing_entity_id',
                'activePrices:id,plan_id,price,interval_count,interval_unit,sort_order',
            ])
            ->get()
            ->keyBy(fn (ProductPlan $p): string => strtolower((string) $p->tld));

        $fallbackEntityId = BillingEntity::where('is_active', true)->orderBy('id')->value('id');

        // invoices.created_by is NOT NULL; a system-generated invoice is
        // attributed to a super_admin (else the first user).
        $systemUserId = User::where('role', 'super_admin')->orderBy('id')->value('id')
            ?? User::orderBy('id')->value('id');
        if ($systemUserId === null) {
            $this->error('No user to attribute system invoices to — aborting.');

            return self::FAILURE;
        }

        $generated = 0;
        $skipped = 0;

        foreach ($domains as $domain) {
            try {
                // Match the domain's TLD to its active is_domain plan.
                $plan = $plansByTld->get(strtolower((string) $domain->tld));
                if ($plan === null) {
                    $this->error(sprintf('Skipped %s — no active domain plan for TLD %s.', $domain->domain, $domain->tld));
                    $skipped++;

                    continue;
                }

                // A domain plan has exactly one active price tier — its
                // duration is the renewal term, its price the renewal price.
                $tier = $plan->activePrices->first();
                if ($tier === null) {
                    $this->error(sprintf('Skipped %s — plan "%s" has no active price tier.', $domain->domain, $plan->name));
                    $skipped++;

                    continue;
                }
                $price = (float) $tier->price;
                if ($price <= 0) {
                    $this->error(sprintf('Skipped %s — plan price resolves to £0.', $domain->domain));
                    $skipped++;

                    continue;
                }

                $entityId = $plan->product->billing_entity_id ?? $fallbackEntityId;
                if ($entityId === null) {
                    $this->error(sprintf('Skipped %s — no billing entity on the plan product.', $domain->domain));
                    $skipped++;

                    continue;
                }

                $description = sprintf(
                    'Domain renewal — %s (%s · %s · expires %s)',
                    $domain->domain,
                    $plan->name,
                    $tier->interval_label,
                    $domain->expiry_date?->format('d M Y'),
                );

                if ($dryRun) {
                    $this->line(sprintf('[dry-run] %s — £%s · %s', $domain->domain, number_format($price, 2), $description));
                    $generated++;

                    continue;
                }

                // VAT from the issuing entity's single source of truth — a
                // non-registered entity yields zero VAT (no hardcoded rate).
                $vat = InvoiceVat::breakdown($price, $this->vatEntity($entityId));

                DB::transaction(function () use ($domain, $plan, $entityId, $price, $vat, $description, $today, $systemUserId): void {
                    $dueDate = $today->copy()->addDays(self::DEFAULT_PAYMENT_TERM_DAYS);

                    $invoice = Invoice::create([
                        'number' => Invoice::generateNextNumber(),
                        'customer_id' => $domain->customer_id,
                        'billing_entity_id' => $entityId,
                        'type' => 'subscription',
                        'status' => 'draft',
                        'subtotal' => $price,
                        'vat_rate' => $vat['vat_rate'],
                        'vat_amount' => $vat['vat_amount'],
                        'total' => $vat['total'],
                        'amount_paid' => 0,
                        'issue_date' => $today->toDateString(),
                        'due_date' => $dueDate->toDateString(),
                        'notes' => 'Auto-generated domain renewal for '.$domain->domain.'.',
                        'created_by' => $systemUserId,
                    ]);

                    InvoiceLine::create([
                        'invoice_id' => $invoice->id,
                        'product_id' => $plan->product_id,
                        'plan_id' => $plan->id,
                        'description' => $description,
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                        'sort_order' => 0,
                    ]);

                    // Stamp the cycle we just billed so a re-run today (and
                    // every run until expiry advances) is a no-op. Sync the
                    // derived plan link from the authoritative TLD match.
                    $domain->update([
                        'renewal_invoiced_for' => $domain->expiry_date,
                        'product_plan_id' => $plan->id,
                    ]);

                    ActivityLog::create([
                        'user_id' => null,
                        'user_role' => 'system',
                        'action' => 'invoice.domain_renewal_generated',
                        'entity_type' => 'invoice',
                        'entity_id' => $invoice->id,
                        'before' => null,
                        'after' => [
                            'number' => $invoice->number,
                            'customer_id' => $invoice->customer_id,
                            'domain_id' => $domain->id,
                            'domain' => $domain->domain,
                            'amount' => $vat['total'],
                        ],
                        'ip_address' => null,
                        'user_agent' => 'artisan:invoices:generate-domain-renewals',
                    ]);

                    $this->info(sprintf('Generated %s for %s — £%s', $invoice->number, $domain->domain, number_format($vat['total'], 2)));
                });

                $generated++;
            } catch (Throwable $e) {
                $this->error(sprintf('Failed domain #%d (%s): %s', $domain->id, $domain->domain, $e->getMessage()));
                $skipped++;
            }
        }

        $this->info(sprintf(
            '%d renewal invoice%s generated, %d skipped.%s',
            $generated,
            $generated === 1 ? '' : 's',
            $skipped,
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Resolve (and memoise) the billing entity used to compute VAT — fetched
     * once per entity rather than per invoice.
     */
    private function vatEntity(int $entityId): ?BillingEntity
    {
        return $this->vatEntities[$entityId] ??= BillingEntity::find($entityId);
    }
}

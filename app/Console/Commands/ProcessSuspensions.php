<?php

namespace App\Console\Commands;

use App\Mail\SuspensionNotice;
use App\Models\ActivityLog;
use App\Models\CustomerProduct;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ProductAutoSuspended;
use App\Services\WebhookDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Auto-suspends a customer's active products when they carry overdue
 * invoices beyond the configured threshold AND a final-notice reminder
 * was sent more than the grace period ago. Both conditions must hold —
 * the threshold gates *which* invoices count; the final-notice gate
 * ensures the customer was actually warned before access is pulled.
 *
 * Suspension here sets suspended_by = null to mark it as system-driven,
 * fires a product webhook, and alerts super_admins.
 */
class ProcessSuspensions extends Command
{
    protected $signature = 'invoices:process-suspensions {--dry-run : List what would be suspended without changing anything}';

    protected $description = 'Automatically suspend customer products when invoices are overdue beyond the configured threshold.';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        $threshold = (int) Setting::getValue('billing.auto_suspend_days', 15);
        $gracePeriod = (int) Setting::getValue('billing.suspension_grace_hours', 24);

        if ($threshold === 0) {
            $this->info('Auto-suspension disabled (threshold = 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($threshold);

        // Group overdue-beyond-threshold invoices by customer.
        $overdueCustomers = Invoice::where('status', 'overdue')
            ->where('due_date', '<=', $cutoff)
            ->with('customer.primaryContact')
            ->get()
            ->groupBy('customer_id');

        $dryRun = (bool) $this->option('dry-run');
        $suspendedCount = 0;

        foreach ($overdueCustomers as $customerId => $invoices) {
            $customer = $invoices->first()->customer;

            if ($customer?->exempt_from_auto_suspend) {
                $this->line('  EXEMPT: '.$customer->name);

                continue;
            }

            // Require a final-notice reminder sent at least grace-period
            // hours ago for one of these invoices.
            $finalNotice = DB::table('activity_log')
                ->where('entity_type', 'invoice')
                ->whereIn('entity_id', $invoices->pluck('id'))
                ->where('action', 'invoice.reminder_sent')
                ->whereJsonContains('after->tier', 'final_notice')
                ->where('created_at', '<=', now()->subHours($gracePeriod))
                ->exists();

            if (! $finalNotice) {
                $this->line('  WAITING (no final notice sent yet): '.($customer->name ?? "customer #{$customerId}"));

                continue;
            }

            $activeProducts = CustomerProduct::where('customer_id', $customerId)
                ->where('status', 'active')
                ->with(['product:id,name,slug', 'customer:id,name'])
                ->get();

            foreach ($activeProducts as $cp) {
                if ($dryRun) {
                    $this->line('  [DRY-RUN] WOULD SUSPEND: '.($customer->name ?? '').' — '.($cp->product->name ?? ''));

                    continue;
                }

                $overdueAmount = (float) $invoices->sum('total');

                DB::transaction(function () use ($cp, $customerId, $threshold, $overdueAmount, $dispatcher): void {
                    $cp->update([
                        'status' => 'suspended',
                        'suspension_reason' => 'non_payment',
                        'suspended_at' => now(),
                        'suspended_by' => null, // null = auto-suspended
                    ]);

                    $dispatcher->dispatchSuspension($cp);

                    // Alert super_admins (bell + email stub).
                    $staffUsers = User::whereIn('role', ['super_admin'])->get();
                    foreach ($staffUsers as $staff) {
                        $staff->notify(new ProductAutoSuspended($cp, $overdueAmount));
                    }

                    ActivityLog::create([
                        'user_id' => null,
                        'user_role' => 'system',
                        'action' => 'customer_product.auto_suspended',
                        'entity_type' => 'customer',
                        'entity_id' => $customerId,
                        'after' => [
                            'product' => $cp->product?->name,
                            'reason' => 'non_payment',
                            'overdue_amount' => $overdueAmount,
                            'overdue_days' => $threshold,
                        ],
                        'ip_address' => null,
                        'user_agent' => 'invoices:process-suspensions',
                    ]);
                });

                // Tell the customer their account was suspended (outside
                // the transaction; mail failure must not undo suspension).
                $contactEmail = $customer?->primaryContact?->email;
                if ($contactEmail !== null) {
                    Mail::to($contactEmail)->send(new SuspensionNotice($cp, $customer));
                }

                $this->info('  SUSPENDED: '.($customer->name ?? '').' — '.($cp->product->name ?? ''));
                $suspendedCount++;
            }
        }

        $this->info($dryRun
            ? 'Dry run complete.'
            : "Done. {$suspendedCount} product".($suspendedCount === 1 ? '' : 's').' suspended.');

        return self::SUCCESS;
    }
}

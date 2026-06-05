<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\PortalUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A customer-portal audit login with KNOWN credentials + a guaranteed
 * UNPAID invoice so the invoice→pay flow is reachable for a visual audit.
 *
 *   email:    portal.demo@whitedash.test
 *   password: password
 *
 * Attaches to existing customer #10 ("Whitedash"), which already has paid
 * invoices, active products (subscriptions) and support tickets — we only
 * add the missing unpaid/overdue invoices. Idempotent; NOT wired into
 * DatabaseSeeder. Run explicitly:
 *   php artisan db:seed --class=PortalAuditSeeder
 */
class PortalAuditSeeder extends Seeder
{
    private const CUSTOMER_ID = 10;

    public function run(): void
    {
        PortalUser::updateOrCreate(
            ['email' => 'portal.demo@whitedash.test'],
            [
                'customer_id' => self::CUSTOMER_ID,
                'name' => 'Demo Customer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        // One payable (sent) and one overdue invoice, each with line items,
        // so the pay page + the overdue state are both auditable.
        $this->makeInvoice('INV-DEMO-UNPAID', 'sent', now(), now()->addDays(14), [
            ['description' => 'Maavelus Hospitality — Pro plan (June 2026)', 'note' => 'Monthly subscription', 'quantity' => 1, 'unit_price' => 250.00],
            ['description' => 'SMS credits top-up', 'note' => '2,000 messages', 'quantity' => 2, 'unit_price' => 60.00],
        ]);

        $this->makeInvoice('INV-DEMO-OVERDUE', 'overdue', now()->subDays(40), now()->subDays(10), [
            ['description' => 'My Order Pad — onboarding & setup', 'note' => 'One-off', 'quantity' => 1, 'unit_price' => 480.00],
        ]);

        $this->command?->info('PortalAuditSeeder: portal.demo@whitedash.test / password ready (customer #'.self::CUSTOMER_ID.', unpaid invoices added).');
    }

    /**
     * @param  array<int, array{description: string, note: string, quantity: float|int, unit_price: float}>  $lines
     */
    private function makeInvoice(string $number, string $status, Carbon $issue, Carbon $due, array $lines): void
    {
        $subtotal = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));
        $vatRate = 20.0;
        $vatAmount = round($subtotal * $vatRate / 100, 2);
        $total = round($subtotal + $vatAmount, 2);

        $invoice = Invoice::updateOrCreate(
            ['number' => $number],
            [
                'customer_id' => self::CUSTOMER_ID,
                'billing_entity_id' => 1,
                'type' => 'service',
                'status' => $status,
                'subtotal' => $subtotal,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total' => $total,
                'amount_paid' => 0,
                'issue_date' => $issue,
                'due_date' => $due,
                'created_by' => 1,
            ],
        );

        // Rebuild lines deterministically on each run.
        $invoice->lines()->delete();
        foreach ($lines as $i => $l) {
            $invoice->lines()->create([
                'description' => $l['description'],
                'note' => $l['note'],
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'amount' => round($l['quantity'] * $l['unit_price'], 2),
                'sort_order' => $i,
            ]);
        }
    }
}

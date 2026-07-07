<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice::generateNextNumber() derives the next number from the highest
 * STRICTLY numeric-suffixed invoice, so a non-numeric-suffixed number
 * (manual/imported, e.g. INV-DEMO-OVERDUE) — even as the most recent row
 * — can't poison the sequence back to INV-0001 and collide. Regression
 * for the v5-verification finding: every invoice-creating path shares
 * this method, and a webhook path retries into the collision forever.
 */
class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

    private BillingEntity $entity;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entity = BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
        ]);
        $this->company = Company::create(['name' => 'Acme']);
        $this->user = User::factory()->create(['role' => 'super_admin']);
    }

    private function invoice(string $number): Invoice
    {
        return Invoice::create([
            'number' => $number,
            'customer_id' => $this->company->id,
            'billing_entity_id' => $this->entity->id,
            'type' => 'subscription',
            'status' => 'sent',
            'subtotal' => 10, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10,
            'amount_paid' => 0,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => $this->user->id,
        ]);
    }

    public function test_first_number_is_inv_0001_on_an_empty_table(): void
    {
        $this->assertSame('INV-0001', Invoice::generateNextNumber());
    }

    public function test_normal_sequential_generation_increments(): void
    {
        $this->invoice('INV-0001');
        $this->assertSame('INV-0002', Invoice::generateNextNumber());

        $this->invoice('INV-0002');
        $this->invoice('INV-0009');
        $this->assertSame('INV-0010', Invoice::generateNextNumber());
    }

    public function test_a_non_numeric_suffix_as_the_latest_row_does_not_collide(): void
    {
        // Real sequence exists…
        $this->invoice('INV-0001');
        $this->invoice('INV-0002');
        // …then a manual/imported non-numeric number lands as the NEWEST row.
        $this->invoice('INV-DEMO-OVERDUE');

        // Old logic anchored on that latest row → INV-0001 → collision.
        // Fixed logic ignores it and continues the numeric sequence.
        $next = Invoice::generateNextNumber();
        $this->assertSame('INV-0003', $next);

        // Prove it doesn't actually collide: creating with it succeeds.
        $this->invoice($next);
        $this->assertDatabaseHas('invoices', ['number' => 'INV-0003']);
    }

    public function test_only_non_numeric_numbers_present_still_starts_at_0001(): void
    {
        // No strictly-numeric invoice yet — only imported oddities.
        $this->invoice('INV-DEMO-OVERDUE');
        $this->invoice('INV-LEGACY-A');

        $this->assertSame('INV-0001', Invoice::generateNextNumber());
    }

    public function test_max_numeric_wins_regardless_of_id_order(): void
    {
        // Highest number is NOT the newest row — a later-created lower
        // number must not roll the sequence backwards.
        $this->invoice('INV-0050');
        $this->invoice('INV-0007');

        $this->assertSame('INV-0051', Invoice::generateNextNumber());
    }
}

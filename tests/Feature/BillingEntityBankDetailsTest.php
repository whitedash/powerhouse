<?php

namespace Tests\Feature;

use App\Models\BillingEntity;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\PortalUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * International bank details (IBAN/BIC) on billing entities + the invoice
 * "Bank transfer" block (PDF + Internal + Portal), shown only when populated.
 * IBAN/BIC are encrypted at rest (like sort_code/account_number), so they're
 * asserted via the model, not assertDatabaseHas.
 */
class BillingEntityBankDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function entity(bool $withBank = true): BillingEntity
    {
        return BillingEntity::create([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
            'bank_name' => $withBank ? 'Barclays' : null,
            'account_name' => $withBank ? 'Whitedash Ltd' : null,
            'sort_code' => $withBank ? '20-00-00' : null,
            'account_number' => $withBank ? '12345678' : null,
            'iban' => $withBank ? 'GB29NWBK60161331926819' : null,
            'bic' => $withBank ? 'NWBKGB2L' : null,
        ]);
    }

    private function invoiceFor(BillingEntity $entity, string $status = 'sent'): Invoice
    {
        $customer = Company::create(['name' => 'Acme '.uniqid()]);

        return Invoice::create([
            'number' => 'INV-'.random_int(1000, 9999),
            'customer_id' => $customer->id,
            'billing_entity_id' => $entity->id,
            'type' => 'subscription', 'status' => $status,
            'subtotal' => 100, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 100, 'amount_paid' => 0,
            'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(14)->toDateString(),
            'created_by' => User::factory()->create(['role' => 'super_admin'])->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'WD', 'legal_name' => 'Whitedash Ltd', 'company_number' => '12345678',
            'address_line1' => '1 St', 'city' => 'London', 'postcode' => 'E1 1AA', 'country' => 'GB',
            'bank_name' => 'Barclays', 'sort_code' => '20-00-00', 'account_number' => '12345678', 'account_name' => 'Whitedash Ltd',
            'postmark_sender_email' => 'b@wd.test', 'postmark_sender_name' => 'WD',
        ], $overrides);
    }

    public function test_entity_saves_iban_and_bic(): void
    {
        $entity = $this->entity(withBank: false);

        $this->actingAs($this->superAdmin())
            ->put("/settings/billing-entities/{$entity->id}", $this->fullPayload([
                'iban' => 'GB29 NWBK 6016 1331 9268 19',
                'bic' => 'NWBKGB2L',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Encrypted at rest → read back through the model (decrypted).
        $fresh = $entity->fresh();
        $this->assertSame('GB29 NWBK 6016 1331 9268 19', $fresh->iban);
        $this->assertSame('NWBKGB2L', $fresh->bic);
    }

    public function test_invalid_iban_and_bic_are_rejected_but_blank_is_allowed(): void
    {
        $entity = $this->entity(withBank: false);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->put("/settings/billing-entities/{$entity->id}", $this->fullPayload(['iban' => 'NOT-AN-IBAN!', 'bic' => 'xx']))
            ->assertSessionHasErrors(['iban', 'bic']);

        // Blank is fine.
        $this->actingAs($admin)
            ->put("/settings/billing-entities/{$entity->id}", $this->fullPayload(['iban' => '', 'bic' => '']))
            ->assertSessionHasNoErrors();
    }

    public function test_pdf_renders_the_bank_block_when_the_entity_has_details(): void
    {
        $invoice = $this->invoiceFor($this->entity(withBank: true))
            ->load(['customer.primaryContact', 'billingEntity', 'lines']);

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'address' => $invoice->billingEntity->address ?? [],
            'billing_email' => null,
            'logo_path' => null,
        ])->render();

        $this->assertStringContainsString('IBAN', $html);
        $this->assertStringContainsString('GB29NWBK60161331926819', $html);
        $this->assertStringContainsString('NWBKGB2L', $html);
    }

    public function test_pdf_omits_the_bank_block_when_the_entity_has_no_details(): void
    {
        $invoice = $this->invoiceFor($this->entity(withBank: false))
            ->load(['customer.primaryContact', 'billingEntity', 'lines']);

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'address' => $invoice->billingEntity->address ?? [],
            'billing_email' => null,
            'logo_path' => null,
        ])->render();

        $this->assertStringNotContainsString('Payment Details', $html);
        $this->assertStringNotContainsString('IBAN', $html);
    }

    public function test_internal_show_payload_carries_iban_and_bic(): void
    {
        $invoice = $this->invoiceFor($this->entity(withBank: true));

        $this->actingAs($this->superAdmin())
            ->get("/invoices/{$invoice->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.billing_entity.iban', 'GB29NWBK60161331926819')
                ->where('invoice.billing_entity.bic', 'NWBKGB2L')
            );
    }

    public function test_portal_shows_bank_block_only_when_populated(): void
    {
        // With bank details → portal payload carries the bank block.
        $withEntity = $this->entity(withBank: true);
        $invWith = $this->invoiceFor($withEntity);
        $portalWith = PortalUser::create(['customer_id' => $invWith->customer_id, 'name' => 'P', 'email' => 'p'.uniqid().'@t.test', 'password' => bcrypt('secret-pass-123')]);

        $this->actingAs($portalWith, 'portal')
            ->get("/portal/invoices/{$invWith->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('invoice.bank.iban', 'GB29NWBK60161331926819')
                ->where('invoice.bank.bic', 'NWBKGB2L')
            );

        // Without → null (no block).
        $invWithout = $this->invoiceFor($this->entity(withBank: false));
        $portalWithout = PortalUser::create(['customer_id' => $invWithout->customer_id, 'name' => 'P', 'email' => 'p'.uniqid().'@t.test', 'password' => bcrypt('secret-pass-123')]);

        $this->actingAs($portalWithout, 'portal')
            ->get("/portal/invoices/{$invWithout->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.bank', null));
    }
}

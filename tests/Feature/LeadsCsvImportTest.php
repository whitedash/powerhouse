<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Leads CSV import — duplicate cascade (email first, phone only when the
 * row has no email), skip-on-lead-match, flag-on-contact-match, intra-run
 * seen-set, per-row validation, FileUploadService gating, permission
 * enforcement, activity_log provenance, and the source='import' →
 * acquisition_channel coercion at conversion.
 */
class LeadsCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Real permission set, so the blocked-without-leads.manage test
        // exercises the live Spatie middleware, not a missing permission.
        $this->seed(RolesAndPermissionsSeeder::class);
        // Uploads land on (and are deleted from) the private disk.
        Storage::fake('private');
        // super_admin passes permission middleware via Gate::before and
        // carries the All leads scope — the import path minus RBAC noise.
        $this->admin = User::factory()->superAdmin()->create();
    }

    private function importCsv(string $content, string $name = 'leads.csv')
    {
        return $this->actingAs($this->admin)->post('/leads/import', [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    private function csv(string ...$dataRows): string
    {
        return implode("\n", ['first_name,last_name,email,phone', ...$dataRows])."\n";
    }

    public function test_clean_import_creates_leads_with_import_source(): void
    {
        $this->importCsv($this->csv(
            'Pat,Smith,pat@acme.test,',
            'Jo,Bloggs,,07700900001',
        ))->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('leads', [
            'first_name' => 'Pat',
            'email' => 'pat@acme.test',
            'source' => 'import',
            'status' => 'new',
            'created_by' => $this->admin->id,
        ]);
        $this->assertSame(2, Lead::count());
    }

    public function test_lead_email_match_skips_the_row(): void
    {
        Lead::create([
            'first_name' => 'Existing', 'email' => 'pat@acme.test',
            'created_by' => $this->admin->id,
        ]);

        $this->importCsv($this->csv('Pat,Smith,pat@acme.test,07700900123'))
            ->assertSessionHas('warning');

        // Only the pre-existing lead remains; the summary names the field.
        $this->assertSame(1, Lead::count());
        $summary = session('import_summary');
        $this->assertSame(0, $summary['created']);
        $this->assertSame('email', $summary['skipped'][0]['matched_on']);
        $this->assertSame(2, $summary['skipped'][0]['row']);
    }

    public function test_phone_only_match_skips_when_email_absent(): void
    {
        Lead::create([
            'first_name' => 'Existing', 'phone' => '07700900123',
            'created_by' => $this->admin->id,
        ]);

        $this->importCsv($this->csv('Jo,Bloggs,,07700900123'))
            ->assertSessionHas('warning');

        $this->assertSame(1, Lead::count());
        $this->assertSame('phone', session('import_summary')['skipped'][0]['matched_on']);
    }

    public function test_contact_email_match_imports_but_flags(): void
    {
        $customer = Customer::create(['name' => 'Acme Co', 'pipeline_stage' => 'active']);
        Contact::create([
            'customer_id' => $customer->id, 'name' => 'Pat Smith',
            'email' => 'pat@acme.test', 'role' => 'owner', 'is_primary' => true,
        ]);

        $this->importCsv($this->csv('Pat,Smith,pat@acme.test,'))
            ->assertSessionHas('warning');

        // Imported anyway (soft match) — flagged with the customer's name.
        $this->assertDatabaseHas('leads', ['email' => 'pat@acme.test', 'source' => 'import']);
        $summary = session('import_summary');
        $this->assertSame(1, $summary['created']);
        $this->assertSame('email', $summary['flagged'][0]['matched_on']);
        $this->assertSame('Acme Co', $summary['flagged'][0]['customer']);
    }

    public function test_row_with_neither_email_nor_phone_fails_validation(): void
    {
        $this->importCsv($this->csv('Pat,Smith,,', 'Jo,Bloggs,jo@x.test,'))
            ->assertSessionHas('warning');

        $summary = session('import_summary');
        $this->assertSame(1, $summary['created']);
        $this->assertSame(2, $summary['failed'][0]['row']);
        $this->assertStringContainsString('cannot be de-duplicated', $summary['failed'][0]['reason']);
    }

    public function test_duplicate_rows_within_the_file_create_one_lead(): void
    {
        $this->importCsv($this->csv(
            'Pat,Smith,pat@acme.test,',
            'Patricia,Smithe,PAT@ACME.TEST,',
        ))->assertSessionHas('warning');

        // Intra-run seen-set: the second row (case-variant email) is skipped.
        $this->assertSame(1, Lead::where('email', 'pat@acme.test')->count());
        $this->assertSame(1, session('import_summary')['created']);
        $this->assertSame(3, session('import_summary')['skipped'][0]['row']);
    }

    public function test_whitespace_padded_email_and_phone_still_match(): void
    {
        Lead::create([
            'first_name' => 'Existing', 'email' => 'pat@acme.test',
            'created_by' => $this->admin->id,
        ]);
        Lead::create([
            'first_name' => 'Existing2', 'phone' => '07700900123',
            'created_by' => $this->admin->id,
        ]);

        // Quoted CSV cells with leading/trailing spaces — the parser must
        // trim them or the collation-backed match silently misses.
        $this->importCsv($this->csv(
            'Pat,Smith,"  pat@acme.test  ",',
            'Jo,Bloggs,,"  07700900123  "',
        ))->assertSessionHas('warning');

        $this->assertSame(2, Lead::count());
        $this->assertCount(2, session('import_summary')['skipped']);
    }

    public function test_non_csv_file_is_rejected_by_the_upload_gate(): void
    {
        $this->actingAs($this->admin)->post('/leads/import', [
            'file' => UploadedFile::fake()->createWithContent('leads.pdf', '%PDF-1.4 not a csv'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Lead::count());
    }

    public function test_import_is_blocked_without_leads_manage(): void
    {
        // UserFactory::afterCreating auto-assigns the Spatie role matching
        // the role column, and seeded staff carries leads.manage — strip it
        // (ExpensesManageEnforcementTest precedent) so the route's
        // permission:leads.manage middleware is what rejects.
        $staff = User::factory()->create();
        $staff->syncRoles([]);

        $this->actingAs($staff->fresh())->post('/leads/import', [
            'file' => UploadedFile::fake()->createWithContent('leads.csv', $this->csv('Pat,,p@x.test,')),
        ])->assertForbidden();

        $this->assertSame(0, Lead::count());
    }

    public function test_each_created_lead_gets_one_provenanced_activity_row(): void
    {
        $this->importCsv($this->csv('Pat,Smith,pat@acme.test,'));

        $lead = Lead::firstOrFail();
        $logs = ActivityLog::where('action', 'lead.created')
            ->where('entity_type', 'lead')
            ->where('entity_id', $lead->id)
            ->get();

        $this->assertCount(1, $logs);
        $this->assertSame('import', $logs[0]->after['via']);
        $this->assertSame('import', $logs[0]->after['source']);
        $this->assertSame($this->admin->id, $logs[0]->user_id);
    }

    public function test_converting_an_imported_lead_coerces_channel_to_other(): void
    {
        $this->importCsv($this->csv('Pat,Smith,pat@acme.test,'));
        $lead = Lead::firstOrFail();

        $this->actingAs($this->admin)->post("/leads/{$lead->id}/convert", [
            'name' => 'Acme Co',
            'type' => 'restaurant',
            'address_line1' => '1 High St',
            'city' => 'London',
            'postcode' => 'EC1A 1AA',
        ])->assertRedirect();

        // 'import' is not a customers.acquisition_channel member — the
        // channelMap must land it on 'other' or MySQL strict mode rejects.
        $this->assertDatabaseHas('customers', [
            'name' => 'Acme Co',
            'acquisition_channel' => 'other',
            'channel_detail' => 'leads.csv',
        ]);
        $this->assertNotNull($lead->fresh()->customer_id);
    }

    public function test_excel_utf8_bom_header_is_recognised(): void
    {
        // Excel's default "CSV UTF-8" export prepends a BOM to the header.
        $this->importCsv("\xEF\xBB\xBF".$this->csv('Pat,Smith,pat@acme.test,'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('leads', ['first_name' => 'Pat', 'email' => 'pat@acme.test']);
    }

    public function test_oversize_estimated_value_fails_the_row_with_a_readable_reason(): void
    {
        $this->importCsv(implode("\n", [
            'first_name,email,estimated_value',
            'Pat,pat@acme.test,9999999999',
        ])."\n")->assertSessionHas('warning');

        $summary = session('import_summary');
        $this->assertSame(0, $summary['created']);
        $this->assertStringContainsString('estimated value', strtolower($summary['failed'][0]['reason']));
    }

    public function test_real_excel_workbook_bytes_are_rejected_with_a_clear_message(): void
    {
        // OLE2 compound-file magic — what a legacy .xls actually starts
        // with. Client MIME (from the .xls extension) passes the upload
        // allowlist; the real-bytes check inside the import must catch it.
        $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 512);

        $this->actingAs($this->admin)->post('/leads/import', [
            'file' => UploadedFile::fake()->createWithContent('leads.xls', $ole),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, Lead::count());
    }

    public function test_template_download_is_gated_and_matches_the_parser_columns(): void
    {
        // Same permission bar as the import itself.
        $stripped = User::factory()->create();
        $stripped->syncRoles([]);
        $this->actingAs($stripped->fresh())->get('/leads/import/template')->assertForbidden();

        $response = $this->actingAs($this->admin)->get('/leads/import/template');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertDownload('leads-import-template.csv');

        // Header row must be exactly what the parser reads, in order.
        $this->assertSame(
            implode(',', LeadImportService::COLUMNS),
            strtok($response->getContent(), "\n"),
        );
    }

    public function test_over_cap_file_is_rejected_whole(): void
    {
        $rows = array_map(
            fn (int $i) => "Lead{$i},,lead{$i}@bulk.test,",
            range(1, LeadImportService::MAX_ROWS + 1),
        );

        $this->importCsv($this->csv(...$rows))->assertSessionHasErrors('file');
        $this->assertSame(0, Lead::count());
    }
}

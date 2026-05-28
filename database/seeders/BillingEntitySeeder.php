<?php

namespace Database\Seeders;

use App\Models\BillingEntity;
use Illuminate\Database\Seeder;

class BillingEntitySeeder extends Seeder
{
    public function run(): void
    {
        BillingEntity::updateOrCreate(
            ['name' => 'Maavelus'],
            [
                'legal_name' => env('MAAVELUS_LEGAL_NAME', 'Maavelus Ltd'),
                'company_number' => env('MAAVELUS_COMPANY_NUMBER'),
                'vat_number' => env('MAAVELUS_VAT_NUMBER'),
                'address' => null,
                'postmark_sender_email' => 'invoices@maavelus.com',
                'postmark_sender_name' => 'Maavelus',
                'is_active' => true,
            ],
        );

        BillingEntity::updateOrCreate(
            ['name' => 'Konstrakt'],
            [
                'legal_name' => env('KONSTRAKT_LEGAL_NAME', 'Konstrakt Ltd'),
                'company_number' => env('KONSTRAKT_COMPANY_NUMBER'),
                'vat_number' => env('KONSTRAKT_VAT_NUMBER'),
                'address' => null,
                'postmark_sender_email' => 'invoices@konstrakt.co.uk',
                'postmark_sender_name' => 'Konstrakt',
                'is_active' => true,
            ],
        );
    }
}

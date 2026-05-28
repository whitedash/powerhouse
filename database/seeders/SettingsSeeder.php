<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'company.name' => 'Whitedash Holdings',
            'invoice.default_vat_rate' => '20.00',
            'invoice.due_days' => '14',
            'invoice.number_prefix' => 'INV-',
            'invoice.next_number' => '1000',
            'support.sla_response_hours' => '24',
            'support.sla_resolve_hours' => '72',
            'features.ai_support_drafts' => '0',
            'features.commission_auto_approve' => '0',
        ];

        foreach ($defaults as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}

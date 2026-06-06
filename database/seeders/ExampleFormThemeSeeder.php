<?php

namespace Database\Seeders;

use App\Models\Form;
use App\Models\FormTheme;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * LOCAL-ONLY: seeds one example theme and attaches it to a self-contained
 * demo form ("theme-demo") so a themed embed can be eyeballed during
 * development.
 *
 * Deliberately NOT registered in DatabaseSeeder and hard-guarded to the
 * local environment — run it explicitly with:
 *
 *     php artisan db:seed --class=Database\\Seeders\\ExampleFormThemeSeeder
 *
 * It never attaches a theme to an existing/production form: it creates its
 * own "theme-demo" form to carry the example theme.
 */
class ExampleFormThemeSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('ExampleFormThemeSeeder skipped — local environment only.');

            return;
        }

        $creatorId = User::min('id') ?? User::factory()->create()->id;

        $theme = FormTheme::firstOrCreate(
            ['name' => 'Example — Ocean'],
            [
                'created_by' => $creatorId,
                // A partial override set; everything else falls back to the
                // default tokens via FormThemeTokens::resolve().
                'tokens' => [
                    'accent' => '#0ea5e9',
                    'focus_ring' => 'rgba(14,165,233,0.18)',
                    'button_bg' => '#0ea5e9',
                    'button_bg_hover' => '#0284c7',
                    'radius' => '12px',
                    'full_width' => true,
                    'heading' => 'Get in touch',
                ],
            ],
        );

        // Self-contained demo form — created here, never a prod form.
        $form = Form::firstOrCreate(
            ['slug' => 'theme-demo'],
            [
                'name' => 'Theme demo',
                'status' => 'active',
                'submit_button_text' => 'Send enquiry',
                'success_message' => "Thanks! We'll be in touch shortly.",
                'webhook_secret' => Str::random(32),
                'created_by' => $creatorId,
            ],
        );

        if ($form->fields()->count() === 0) {
            $form->fields()->createMany([
                ['label' => 'Name', 'field_key' => 'name', 'type' => 'text', 'is_required' => true, 'sort_order' => 1],
                ['label' => 'Email', 'field_key' => 'email', 'type' => 'email', 'is_required' => true, 'sort_order' => 2],
                ['label' => 'Message', 'field_key' => 'message', 'type' => 'textarea', 'is_required' => false, 'sort_order' => 3],
            ]);
        }

        $form->update(['theme_id' => $theme->id]);

        $this->command?->info("Seeded theme '{$theme->name}' on the local 'theme-demo' form (/forms/theme-demo/embed.js).");
    }
}

<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public embeddable forms get an OPEN, credential-less CORS policy via
 * App\Http\Middleware\FormCors — decoupled from the credentialed global
 * config/cors.php (which can't use '*' alongside supports_credentials).
 */
class FormCorsTest extends TestCase
{
    use RefreshDatabase;

    private function activeForm(bool $requireEmail = true): Form
    {
        $form = Form::create([
            'name' => 'Contact',
            'slug' => 'contact-us',
            'status' => 'active',
            'submit_button_text' => 'Submit',
            'webhook_secret' => Str::random(32),
            'created_by' => User::factory()->create()->id,
        ]);

        $form->fields()->create([
            'label' => 'Email',
            'field_key' => 'email',
            'type' => 'email',
            'is_required' => $requireEmail,
            'sort_order' => 1,
        ]);

        return $form;
    }

    public function test_options_preflight_on_submit_returns_open_policy(): void
    {
        $this->activeForm();

        $res = $this->call('OPTIONS', '/forms/contact-us/submit', [], [], [], [
            'HTTP_ORIGIN' => 'https://anysite.example',
        ]);

        $res->assertNoContent(204);
        $res->assertHeader('Access-Control-Allow-Origin', '*');
        $res->assertHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $res->assertHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
        $this->assertNotNull($res->headers->get('Access-Control-Max-Age'));
        // Open policy → NO credentials (that's the whole point).
        $this->assertNull($res->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_successful_submit_carries_open_cors_without_credentials(): void
    {
        $this->activeForm();

        $res = $this->withHeaders([
            'Origin' => 'https://anysite.example',
            'Accept' => 'application/json',
        ])->postJson('/forms/contact-us/submit', ['email' => 'lead@example.com']);

        $res->assertOk();
        $res->assertHeader('Access-Control-Allow-Origin', '*');
        // No Allow-Credentials — open policy, clients post with credentials:'omit'.
        $this->assertNull($res->headers->get('Access-Control-Allow-Credentials'));
        // NB: FormCors sets `Vary: Origin`, but Laravel's Inertia middleware
        // runs later in the stack and re-sets Vary to X-Inertia on this path.
        // Harmless here: Allow-Origin is a constant '*' (identical for every
        // origin), so there is no cache-keying concern that Vary would fix.
    }

    public function test_validation_error_response_still_carries_cors(): void
    {
        // The widget reads r.json.errors from the 422 cross-origin, so the
        // error response MUST carry the Allow-Origin header too (exceptions
        // are rendered inside the routing pipeline, so FormCors still runs).
        $this->activeForm();

        $res = $this->withHeaders([
            'Origin' => 'https://anysite.example',
            'Accept' => 'application/json',
        ])->postJson('/forms/contact-us/submit', []); // missing required email

        $res->assertStatus(422);
        $res->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_embed_js_carries_open_cors(): void
    {
        $this->activeForm();

        $res = $this->get('/forms/contact-us/embed.js');

        $res->assertOk();
        $res->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_global_cors_config_does_not_cover_forms(): void
    {
        // Forms are deliberately excluded from the credentialed global policy.
        $this->assertNotContains('forms/*', config('cors.paths'));
        $this->assertContains('api/*', config('cors.paths'));
        $this->assertTrue(config('cors.supports_credentials'));
    }
}

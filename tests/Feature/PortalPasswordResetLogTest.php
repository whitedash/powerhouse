<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PortalUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * H2 — a portal password-reset request must NOT write the single-use reset
 * token (or the reset URL that embeds it) to the application log. The reset URL
 * carries the plaintext token; logging it (CWE-532) let anyone with log access
 * complete the reset and take over the account. The log keeps only the email +
 * the event.
 */
class PortalPasswordResetLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_request_does_not_log_the_token_or_reset_url(): void
    {
        Mail::fake();
        Log::spy();

        $customer = Customer::create(['name' => 'Acme']);
        PortalUser::create([
            'customer_id' => $customer->id,
            'name' => 'Lead',
            'email' => 'lead@example.com',
            'password' => 'Secret!12345',
        ]);

        $this->post('/portal/forgot-password', ['email' => 'lead@example.com'])
            ->assertSessionHasNoErrors();

        // The info log still fires, but its context must carry NEITHER the
        // reset_url key NOR any value containing the token / reset path.
        Log::shouldHaveReceived('info')
            ->with('Portal password reset requested', \Mockery::on(function (array $ctx): bool {
                $json = (string) json_encode($ctx);

                return ! array_key_exists('reset_url', $ctx)
                    && ! str_contains($json, 'token=')
                    && ! str_contains($json, '/portal/reset-password');
            }))
            ->once();
    }
}

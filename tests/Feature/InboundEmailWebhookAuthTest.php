<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3 — the Postmark inbound-email webhook must fail CLOSED when the shared
 * secret is unset. Previously the HMAC check sat behind `if ($expected !== '')`,
 * so an unset POSTMARK_INBOUND_SECRET skipped verification entirely (an
 * unauthenticated forgery surface). It now aborts 401 when the secret is empty,
 * while still verifying a correctly-signed request when the secret is set.
 */
class InboundEmailWebhookAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $payload = [
        'From' => 'sender@example.com',
        'Subject' => 'Help please',
        'TextBody' => 'My order is late.',
        'To' => 'support@whitedash.com',
    ];

    public function test_aborts_401_when_inbound_secret_is_empty(): void
    {
        config(['services.postmark.inbound_secret' => '']);

        $this->postJson('/webhooks/email/inbound', $this->payload)
            ->assertStatus(401);

        // Nothing processed — no ticket created from the unauthenticated request.
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_aborts_401_with_a_wrong_signature_when_secret_is_set(): void
    {
        config(['services.postmark.inbound_secret' => 'inbound-shh']);

        $this->withHeaders(['X-Postmark-Signature' => 'wrong'])
            ->postJson('/webhooks/email/inbound', $this->payload)
            ->assertStatus(401);

        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_processes_a_correctly_signed_request_when_secret_is_set(): void
    {
        config(['services.postmark.inbound_secret' => 'inbound-shh']);

        $this->withHeaders(['X-Postmark-Signature' => 'inbound-shh'])
            ->postJson('/webhooks/email/inbound', $this->payload)
            ->assertOk()
            ->assertJson(['received' => true]);

        $this->assertDatabaseHas('support_tickets', ['subject' => 'Help please']);
    }
}

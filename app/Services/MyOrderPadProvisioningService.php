<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\CustomerProduct;
use App\Models\ProductPlan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates (or links) the MyOrderPad account behind a Powerhouse
 * subscription. MyOrderPad is multi-tenant: provisioning there creates a
 * restaurant tenant + owner user, returning that user's id which we store
 * as external_user_id so the SSO launch can resolve the account.
 *
 * Idempotency is the caller's concern (the job/launch both skip when
 * external_user_id is already set); MyOrderPad's endpoint is itself
 * idempotent on powerhouse_customer_id.
 */
class MyOrderPadProvisioningService
{
    /**
     * @return array<string, mixed> the MyOrderPad provision response
     */
    public function provisionUser(CustomerProduct $subscription): array
    {
        $customer = $subscription->customer;
        /** @var Contact|null $contact */
        $contact = $customer->primaryContact ?? $customer->contacts()->first();

        abort_unless(
            (bool) $contact?->email,
            422,
            'Customer has no email address. Add one before activating MyOrderPad.',
        );

        // product_plans has no slug column — derive a stable plan string
        // from the plan name (e.g. "Pro Monthly" -> "pro-monthly"). Resolved
        // via a scalar query (plan_id is nullable) so it's null-safe; the
        // consumer stores this on its tenant. Default to free.
        $planName = $subscription->plan_id !== null
            ? ProductPlan::whereKey($subscription->plan_id)->value('name')
            : null;
        $plan = Str::slug((string) ($planName ?? 'free')) ?: 'free';

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.(string) config('services.myorderpad.api_key'),
            'Accept' => 'application/json',
        ])->timeout(15)->post(
            rtrim((string) config('services.myorderpad.url'), '/').'/api/powerhouse/provision',
            [
                'powerhouse_customer_id' => $customer->id,
                'company_name' => $customer->name,
                'email' => $contact->email,
                'name' => $contact->name,
                'plan' => $plan,
            ],
        );

        if ($response->failed()) {
            // Record the failure on the row so the internal UI surfaces it,
            // then throw so the queued job retries with backoff.
            $subscription->update(['provision_status' => 'failed']);

            throw new \RuntimeException(
                'MyOrderPad provisioning failed: '.$response->status().' '.$response->body(),
            );
        }

        $data = $response->json();

        $subscription->update([
            'external_user_id' => (string) $data['user_id'],
            'external_email' => $contact->email,
            'provisioned_at' => now(),
            'provision_status' => 'active',
        ]);

        Log::info('myorderpad.provisioned', [
            'customer_id' => $customer->id,
            'myorderpad_user_id' => $data['user_id'],
        ]);

        return $data;
    }
}

<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\CustomerProduct;
use App\Models\WebhookDelivery;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single outbound point for product webhooks. Every event Powerhouse
 * pushes to a consumer product (Maavelus, MyOrderPad, …) goes through
 * here — controllers and commands must never call Http:: directly for
 * product notifications, so signing, the delivery ledger and the retry
 * policy stay in one place.
 *
 * Delivery model: dispatch() writes a 'pending' WebhookDelivery, then
 * either queues a DeliverWebhook job (async, default) or delivers inline
 * (for critical events). deliver() owns the HTTP call, status transitions
 * and exponential backoff. Retries are driven by the service (the job is
 * tries=1), so a single code path governs the policy.
 */
class WebhookDispatcher
{
    /**
     * Resolve the product's webhook endpoint + secret from config.
     * Matching is prefix-based so versioned slugs (maavelus, maavelus-v2)
     * resolve to the same product. Returns null for unknown products.
     *
     * @return array{url: ?string, webhook_path: string, secret: ?string, enabled: bool}|null
     */
    private function getProductConfig(string $slug): ?array
    {
        return match (true) {
            str_starts_with($slug, 'maavelus') => [
                'url' => config('services.products.maavelus_url'),
                'webhook_path' => '/wp-json/powerhouse/v1/webhook',
                'secret' => config('services.products.maavelus_secret'),
                'enabled' => ! empty(config('services.products.maavelus_url')),
            ],
            str_starts_with($slug, 'myorderpad') => [
                'url' => config('services.products.myorderpad_url'),
                'webhook_path' => '/api/powerhouse/webhook',
                'secret' => config('services.products.myorderpad_secret'),
                'enabled' => ! empty(config('services.products.myorderpad_url')),
            ],
            default => null,
        };
    }

    /**
     * Record + send (or queue) a webhook for one event. Returns the
     * created WebhookDelivery, or null when the product isn't configured
     * (no URL) — a no-op rather than an error so callers can fire events
     * unconditionally.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $eventType, string $productSlug, array $payload, bool $async = true): ?WebhookDelivery
    {
        $config = $this->getProductConfig($productSlug);

        if ($config === null || ! $config['enabled']) {
            Log::info('Webhook skipped — product not configured: '.$productSlug);

            return null;
        }

        $fullUrl = rtrim((string) $config['url'], '/').$config['webhook_path'];

        $fullPayload = array_merge($payload, [
            'event' => $eventType,
            'sent_at' => now()->toISOString(),
            'powerhouse_version' => '1.0',
        ]);

        $signature = 'sha256='.hash_hmac(
            'sha256',
            (string) json_encode($fullPayload),
            (string) ($config['secret'] ?? ''),
        );

        $delivery = WebhookDelivery::create([
            'event_type' => $eventType,
            'product_slug' => $productSlug,
            'payload' => $fullPayload,
            'target_url' => $fullUrl,
            'signature' => $signature,
            'status' => 'pending',
        ]);

        if ($async) {
            DeliverWebhook::dispatch($delivery);
        } else {
            $this->deliver($delivery);
        }

        return $delivery;
    }

    /**
     * Perform one delivery attempt and record the outcome. Exponential
     * backoff schedules the next attempt at 5, 10, 20 minutes; once
     * max_attempts is reached without success the row is abandoned.
     * Bails out early if the delivery is no longer retryable.
     */
    public function deliver(WebhookDelivery $delivery): void
    {
        if (! $delivery->canRetry()) {
            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-Powerhouse-Signature' => $delivery->signature,
                    'X-Powerhouse-Event' => $delivery->event_type,
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Powerhouse/1.0',
                ])
                ->post($delivery->target_url, $delivery->payload);

            $attempts = $delivery->attempts + 1;
            $succeeded = $response->successful();
            $exhausted = ! $succeeded && $attempts >= $delivery->max_attempts;

            $delivery->update([
                'status' => $succeeded ? 'delivered' : ($exhausted ? 'abandoned' : 'failed'),
                'http_status' => $response->status(),
                'response_body' => Str::limit($response->body(), 500),
                'attempts' => $attempts,
                'delivered_at' => $succeeded ? now() : null,
                // Backoff only while attempts remain: 2^attempts * 5 min.
                'next_retry_at' => (! $succeeded && ! $exhausted)
                    ? now()->addMinutes((2 ** $delivery->attempts) * 5)
                    : null,
            ]);

            if ($exhausted) {
                Log::error('Webhook abandoned after '.$delivery->max_attempts.' attempts', [
                    'delivery_id' => $delivery->id,
                    'event' => $delivery->event_type,
                ]);
            }
        } catch (\Throwable $e) {
            $attempts = $delivery->attempts + 1;
            $exhausted = $attempts >= $delivery->max_attempts;

            $delivery->update([
                'status' => $exhausted ? 'abandoned' : 'failed',
                'attempts' => $attempts,
                'response_body' => Str::limit($e->getMessage(), 500),
                'next_retry_at' => $exhausted ? null : now()->addMinutes((2 ** $delivery->attempts) * 5),
            ]);

            if ($exhausted) {
                Log::error('Webhook abandoned (exception) after '.$delivery->max_attempts.' attempts', [
                    'delivery_id' => $delivery->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function dispatchSuspension(CustomerProduct $cp): void
    {
        $this->dispatch('customer_product.suspended', $cp->product->slug ?? '', [
            'customer_id' => $cp->customer_id,
            'customer_name' => $cp->customer?->name,
            'product_slug' => $cp->product?->slug,
            'plan' => $cp->productPlan?->name,
            'reason' => $cp->suspension_reason,
            'suspended_at' => $cp->suspended_at?->toISOString(),
        ]);

        $this->suspendWhmAccounts($cp);
    }

    public function dispatchReinstatement(CustomerProduct $cp): void
    {
        $this->dispatch('customer_product.reinstated', $cp->product->slug ?? '', [
            'customer_id' => $cp->customer_id,
            'customer_name' => $cp->customer?->name,
            'product_slug' => $cp->product?->slug,
            'plan' => $cp->productPlan?->name,
            'reinstated_at' => $cp->reinstated_at?->toISOString(),
        ]);

        $this->unsuspendWhmAccounts($cp);
    }

    /**
     * Suspend the cPanel accounts of any WHM-managed websites tied to this
     * subscription. Centralised here so both the manual suspend
     * (CustomerProductController) and the auto-suspend sweep
     * (ProcessSuspensions) trigger it via the single dispatch point —
     * never both, so suspendacct isn't called twice. WHM failures are
     * logged but never bubble up: losing the server call must not roll
     * back the subscription suspension or the product webhook.
     */
    private function suspendWhmAccounts(CustomerProduct $cp): void
    {
        $websites = Website::where('customer_product_id', $cp->id)
            ->where('whm_managed', true)
            ->whereNotNull('cpanel_username')
            ->get();

        foreach ($websites as $website) {
            try {
                $ok = app(WhmService::class)->suspendAccount(
                    (string) $website->cpanel_username,
                    'Non-payment — auto-suspended by Powerhouse',
                );
                if ($ok) {
                    $website->update(['status' => 'suspended']);
                }
            } catch (\Throwable $e) {
                Log::error('WHM suspend failed', [
                    'website_id' => $website->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function unsuspendWhmAccounts(CustomerProduct $cp): void
    {
        $websites = Website::where('customer_product_id', $cp->id)
            ->where('whm_managed', true)
            ->whereNotNull('cpanel_username')
            ->get();

        foreach ($websites as $website) {
            try {
                app(WhmService::class)->unsuspendAccount((string) $website->cpanel_username);
                $website->update(['status' => 'active']);
            } catch (\Throwable $e) {
                Log::error('WHM unsuspend failed', [
                    'website_id' => $website->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function dispatchActivation(CustomerProduct $cp): void
    {
        $this->dispatch('customer_product.activated', $cp->product->slug ?? '', [
            'customer_id' => $cp->customer_id,
            'customer_name' => $cp->customer?->name,
            'product_slug' => $cp->product?->slug,
            'plan' => $cp->productPlan?->name,
            'status' => $cp->status,
        ]);
    }
}

# Powerhouse Go-Live Checklist

Target: **hub.whitedash.co.uk** on **040hosting.eu**.
Run top-to-bottom. Don't tick "Go-live" until `php artisan smoke:test`
is all ✅.

## Pre-deployment (local)
- [ ] All feature branches merged to `main`
- [ ] `php artisan test` (if tests exist)
- [ ] `npm run build` (clean, no warnings)
- [ ] `vendor/bin/phpstan analyse --memory-limit=1G` (0 errors)
- [ ] `vendor/bin/pint` (clean)
- [ ] `composer audit` + `npm audit` (0 vulns)
- [ ] Review test data and delete it (4 customers incl. one named
      "test", 5 invoices, 3 contacts, 3 users in the dev DB)
- [ ] `php artisan export:production`
- [ ] Verify export file size is sensible

## Server setup (040hosting.eu)
- [ ] Create hosting account for hub.whitedash.co.uk
- [ ] **PHP 8.3+** enabled (composer requires `^8.3`)
- [ ] PHP extensions: pdo_mysql, mbstring, openssl, gd (or imagick),
      dom/xml, bcmath, curl, zip, intl
- [ ] MySQL database + user created
- [ ] Composer installed (or vendor/ uploaded)
- [ ] Node.js available (or build assets locally and upload `public/build`)

## DNS (Cloudflare)
- [ ] A record: `hub` → [server IP], Proxied: ON
- [ ] MX record: `support` → inbound.postmarkapp.com (Priority 10)
- [ ] SPF record: includes `spf.postmarkapp.com`
- [ ] DKIM record from Postmark
- [ ] DMARC record

## Deployment
- [ ] Upload codebase (git clone or zip)
- [ ] Copy `.env.production.example` → `.env`, fill EVERY blank
- [ ] `php artisan key:generate` (if APP_KEY blank)
- [ ] **Rotate** `CLOUDFLARE_API_TOKEN` + `PAGESPEED_API_KEY` —
      the dev values were committed and must not be reused
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] Prefer deploying a full `composer install --no-dev --optimize-autoloader`
      vendor bundle over surgical per-package uploads, so runtime
      transitive/suggested deps (e.g. `symfony/http-client`, needed by the
      Postmark mailer) are never missed
- [ ] `php artisan setup:production`
- [ ] Import production SQL dump
- [ ] Upload `storage/app` files (logos, PDFs)
- [ ] Confirm `storage/` and `bootstrap/cache/` are writable

## Queue worker + scheduler
- [ ] Cron: `* * * * * php artisan schedule:run >> /dev/null 2>&1`
      (drives all 10 scheduled commands + health-cache warmer)
- [ ] Queue worker running (cPanel process manager or supervised cron):
      `php artisan queue:work --queue=webhooks,default --sleep=3 --tries=3 --timeout=60 --max-time=3600`
      — the `webhooks` queue MUST come first (DeliverWebhook runs there)

## Stripe (live mode)
- [ ] `STRIPE_KEY` = `pk_live_...`
- [ ] `STRIPE_SECRET` = `sk_live_...`
- [ ] Register prod webhook at hub.whitedash.co.uk/webhooks/stripe
- [ ] Copy signing secret to `STRIPE_WEBHOOK_SECRET`

## Postmark
- [ ] `POSTMARK_TOKEN` = server token
- [ ] Verify `symfony/http-client` is present in the deployed `vendor/` —
      the Postmark mailer needs it at runtime but does NOT hard-require it,
      so its absence makes ALL outbound mail throw "HttpClient component is
      not installed". Confirm on prod:
      `php artisan tinker --execute="var_dump(class_exists(Symfony\\Component\\HttpClient\\HttpClient::class));"`
      → must be `true`
- [ ] Inbound webhook configured (with `POSTMARK_INBOUND_SECRET`)
- [ ] Send a test email from Settings

## Smoke test
- [ ] `php artisan smoke:test` → **all ✅** before going live
- [ ] Manual: login, create invoice, check portal, run a PageSpeed check

## Go-live
- [ ] Remove password protection / maintenance mode (`php artisan up`)
- [ ] Announce to team
- [ ] Monitor first 24h: `tail -f storage/logs/laravel.log`

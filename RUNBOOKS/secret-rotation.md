# Secret Rotation Runbook

## When to rotate

**Immediately** — suspected compromise, staff departure with infra access, vendor security incident.
**Scheduled** — API keys every 12 months; Passport encryption keys every 6 months.

## How to rotate each secret

### `APP_KEY` (Laravel encryption key)

1. `php artisan key:generate --show` → captures a new key (does NOT write to .env).
2. Update production `.env` `APP_KEY=`.
3. **All `encrypted`-cast columns** (bank details on `billing_entities`, `two_factor_secret` on `users`, QBO tokens) become unreadable until re-encrypted under the new key.
4. ⚠️ Run **only during a maintenance window**. Pre-rotation: dump the affected tables, decrypt under the old key, re-encrypt under the new key, restore.
5. `php artisan config:cache` on production.

### Passport encryption keys (`storage/oauth-private.key`, `storage/oauth-public.key`)

1. `php artisan passport:keys --force`
2. **All existing access + refresh tokens are immediately invalidated.** Every connected control panel (Maavelus, MyOrderPad, customer portal sessions over OAuth) must re-authenticate.
3. ⚠️ Schedule during a low-traffic window. Notify customers 24h in advance.
4. Deploy the new keys (they're in `storage/`, not committed — push via your secret store).

### Stripe API keys

1. Generate a new key in the Stripe dashboard (set permissions identical to the old one).
2. Update `.env` `STRIPE_KEY` + `STRIPE_SECRET` locally + production.
3. **Update the webhook signing secret too** if you rotated webhooks.
4. Verify: send a card-charge test via tinker.
5. **Deactivate** (don't delete) the old key in Stripe. Keep 24h for audit and rollback.

### Postmark API token

1. Generate a new server token in the Postmark UI.
2. Update `.env` `POSTMARK_TOKEN`.
3. Test: `php artisan tinker` → fire a transactional send.
4. Deactivate the old token.

### Cloudflare API token

1. Create a new token in the Cloudflare dashboard. Match the scope (Zone:Read, DNS:Edit, SSL:Edit on the same account).
2. Update `.env` `CLOUDFLARE_API_TOKEN`.
3. Test: `php artisan tinker` → call `CloudflareService::syncZones()` (or any read call).
4. Delete the old token from Cloudflare.

### QuickBooks Online credentials

1. Rotate the client secret in the QBO developer console.
2. Update `.env` `QBO_CLIENT_SECRET`.
3. Re-run the OAuth handshake for each `billing_entity` (forces fresh `qbo_access_token` + `qbo_refresh_token` — both stored encrypted).

## After any rotation

- [ ] `.env` updated on production
- [ ] `php artisan config:cache` on production
- [ ] Exercise the affected feature end-to-end
- [ ] Append a line to DECISION-LOG.md: date + secret + reason
- [ ] Confirm the old credential no longer works (positive-control test)

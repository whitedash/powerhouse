# Security Checklist — Production Deploy

## Before every production deploy

- [ ] `APP_ENV=production` in `.env`
- [ ] `APP_DEBUG=false` in `.env`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_HTTP_ONLY=true`
- [ ] `SESSION_SAME_SITE=lax`
- [ ] `DEBUGBAR_ENABLED=false`
- [ ] All passwords meet 12-char policy (min 12, mixed case, number, symbol)
- [ ] `composer audit` — zero high/critical
- [ ] `npm audit` — zero high/critical
- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] Verify `.env` is **not** in git history (`git log --all --full-history -- .env`)
- [ ] Verify storage permissions: `chmod -R 775 storage bootstrap/cache` and owned by web user
- [ ] Passport encryption keys present in `storage/oauth-*.key` (not committed; deploy via secret store)

## Nginx production config (document — not automated)

- [ ] `add_header X-Frame-Options SAMEORIGIN always;`
- [ ] `add_header X-Content-Type-Options nosniff always;`
- [ ] `server_tokens off;` (hide Nginx version)
- [ ] TLS 1.2+ only (`ssl_protocols TLSv1.2 TLSv1.3;`)
- [ ] HSTS header enabled (Laravel sets it when `APP_ENV=production`, but a duplicate at Nginx level survives any app crash)
- [ ] SSL certificate auto-renewal via certbot
- [ ] Block `/.env`, `/.git`, `/composer.*`, `/package.json` paths at the edge
- [ ] **Defense in depth for upload paths** — even though `FileUploadService` stores under `storage/app/private/uploads` (outside the document root), deny script execution in any `storage/` path that ever becomes web-served:

  ```nginx
  location ~* \.(php|pl|py|jsp|asp|sh|cgi)$ {
      deny all;
  }
  ```

## Ongoing

- [ ] Run `composer audit` weekly
- [ ] Run `npm audit` weekly
- [ ] Review `activity_log` for `auth.failed` anomalies weekly
- [ ] Rotate Passport encryption keys every 6 months (`php artisan passport:keys --force` + redeploy + invalidate tokens)
- [ ] Rotate third-party API keys (Cloudflare, Postmark, Stripe, QBO) every 12 months
- [ ] Quarterly review of `Policies/` and middleware aliases — confirm no route can skip role/auth checks

## Future monitoring rules (Phase 10 / admin panel)

- More than 10 `auth.failed` events from one IP in 1 hour → page on-call
- Login from a new country (needs GeoIP) → email user
- Mass-export events (>1000 records exported via portal) → notify admin
- `activity_log` write rate doubles vs 7-day baseline → investigate
- **Weekly:** query `activity_log` for `action = 'security.mass_export_detected'` and review the originating user / endpoint.

## OAuth client policy (Passport)

When adding new OAuth clients:

- **Never** use the implicit grant.
- **Always** require PKCE with `code_challenge_method=S256`. Enforced server-side by the `RequirePkce` middleware on `/oauth/authorize` (Passport 13 has no per-client PKCE column, so we gate at the request layer instead).
- Choose the shortest practical token expiry (current defaults: access 15d, refresh 30d, PAT 6mo).
- Confidential clients (with a secret) and public clients (no secret) are both subject to PKCE — defence in depth.

## Subdomain takeover prevention

A customer domain pointing to Powerhouse infrastructure via DNS CNAME can be hijacked if the customer churns and DNS isn't cleaned up. Procedure when a customer is deprovisioned:

1. Remove CNAME / A records pointing to Powerhouse from the customer's domain in Cloudflare.
2. Verify with `dig {domain}` — must not resolve to Powerhouse IPs.
3. If the customer registered their own domain elsewhere, notify them in writing to update DNS.
4. Log the deprovisioning to `activity_log` as `domain.customer_removed` with `after = {domain, action_required: 'verify_dns_cleanup'}`.

This procedure becomes part of the customer offboarding checklist in the GDPR sprint.

## OWASP Top 10 — pre-launch verification

- [ ] **A01 Broken Access Control** — every route hit with the wrong role via tinker (switch user role, hit each endpoint). Portal user accessing another customer's data. Referrer accessing another referrer's commissions.
- [ ] **A02 Cryptographic Failures** — `php artisan key:show` shows a 32-byte `APP_KEY`. All sensitive DB fields use `encrypted` cast. HTTPS forced in production. No plaintext passwords in DB or logs.
- [ ] **A03 Injection** — every search input fuzzed with `' OR 1=1 --` and `<script>alert(1)</script>`. Test each parameter-accepting route from `php artisan route:list`.
- [ ] **A04 Insecure Design** — login, logout, session expiry, concurrent sessions. Rate limit confirmed on all auth endpoints.
- [ ] **A05 Security Misconfiguration** — `APP_DEBUG=false` in prod. `curl -I https://hub.whitedash.co.uk` shows no `X-Powered-By`. Check securityheaders.com against the production URL.
- [ ] **A06 Vulnerable Components** — `composer audit` and `npm audit` both clean. github.com/advisories scanned for Laravel + Passport.
- [ ] **A07 Identification & Auth Failures** — 6 failed logins trigger lockout. Session expires after 8h. Logout invalidates server-side. Password-reset tokens expire (1h).
- [ ] **A08 Software & Data Integrity Failures** — every webhook checks its signature. `composer.lock` + `package-lock.json` committed.
- [ ] **A09 Logging & Monitoring Failures** — every auth event in `activity_log`. Failed login attempts include IP. Mass-export detection active. Trigger a synthetic mass-export and verify the alert mail fires.
- [ ] **A10 SSRF** — every field that accepts a URL uses `App\Rules\NotInternalUrl`.

## Threat model summary (what we have defended)

| Threat | Defence |
|---|---|
| Brute-force login | `throttle:staff-login` (5/15min per email+IP), all failures logged to `activity_log` |
| Session hijack | `secure` (prod) + `http_only` + `samesite=lax` cookies; JSON session serialization (no PHP gadget chains) |
| XSS | Vue auto-escapes; CSP header restricts script sources; `X-XSS-Protection: 1; mode=block` |
| Clickjacking | `X-Frame-Options: SAMEORIGIN` + `frame-ancestors 'none'` in CSP |
| MIME sniffing | `X-Content-Type-Options: nosniff` |
| CSRF | Laravel web middleware group `VerifyCsrfToken` + Inertia automatic token |
| SQL injection | Eloquent / parameter binding everywhere; no raw interpolation (verified by grep) |
| Mass assignment | Every model declares `$fillable` (verified by grep) |
| Sensitive data at rest | Encrypted casts on bank details + QBO tokens + 2FA secret |
| Log leak of secrets | `RedactSensitiveData` Monolog processor on file channels |
| Lazy-loading regressions | `Model::preventLazyLoading` in non-production |
| Insecure deps | `composer audit` and `npm audit` in startup checklist |
| File upload exploits (XSS via SVG, EXIF, MIME spoofing, path traversal) | `FileUploadService` — size + real-byte MIME check, UUID filenames, EXIF stripped via Intervention re-encode, SVG sanitised, stored on the private disk only |
| OAuth code interception | PKCE (S256) required on every `/oauth/authorize` request via `RequirePkce` middleware. Implicit grant never enabled. |
| Webhook spoofing / replay | `VerifyWebhookSignature` abstract middleware uses `hash_equals()`. `WebhookIdempotencyService` + `webhook_events` table dedupe by `(source, event_id)`. |
| SSRF (URLs pointing inside the perimeter) | `App\Rules\NotInternalUrl` validation rule rejects private / loopback / link-local / AWS metadata addresses on any URL field |
| Mass data exfiltration | `throttle:60,1` on listing endpoints; `DetectMassExport` listener fires `security.mass_export_detected` to `activity_log` + emails super admin at 50 paginated hits / 10 min |
| Timing attacks on token comparison | `hash_equals()` everywhere — `==`/`===` against tokens is banned in CLAUDE.md |
| IDOR | All controller methods that take an ID use `findOrFail()` + `$this->authorize()`. Portal queries use `Customer::forPortalUser($id)` scope. Referrer queries always filter by `auth()->user()->referrer->id`. |

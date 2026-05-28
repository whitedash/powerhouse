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

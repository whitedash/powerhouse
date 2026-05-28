# Decision Log — Powerhouse

| Date | Decision | Rationale |
|---|---|---|
| May 2026 | Laravel + Vue SPA | Consistent with MyOrderPad; better suited to application logic than WordPress |
| May 2026 | Laravel Passport for OAuth 2.0 | Central auth server for all Whitedash products |
| May 2026 | MySQL | Consistent with existing stack |
| May 2026 | HTTPS for GitHub remote | No SSH keys configured on dev machine |
| May 2026 | Products table database-driven | Supports adding future products without code deploys |
| May 2026 | Powerhouse never commercialised | It is Apostolos's operating layer, not a product |
| May 2026 | Each product control panel investor-ready | Any product can be spun out independently of Powerhouse |
| May 2026 | Universal customer account via OAuth 2.0 | One Whitedash relationship per customer; brand identity |
| May 2026 | Multi-entity invoicing from day one | Future LTD companies must be supportable without schema changes |
| May 2026 | Commission rules use JSON config | Flexible for all models; new products need no schema changes |
| May 2026 | Security sprint before any customer data | FormRequests, Policies, headers, encrypted casts, rate-limit, audit log; SECURITY.md captures the deploy checklist |
| May 2026 | Session-based staff auth, not Passport | Passport is reserved for OAuth API (product control panels). Staff use Laravel's `web` guard. |
| May 2026 | Login throttle = 5 attempts per 15 min per (email+IP) | Spec is "5 per minute"; widened the window to 15 min — same allowance, blocks slow distributed brute-force, clears on successful login |
| May 2026 | `Model::preventLazyLoading` enabled in non-production | Forces explicit eager loads. Catches N+1 in development and CI without slowing production |
| May 2026 | Sensitive billing fields encrypted at rest | `sort_code`, `account_number`, `account_name`, `qbo_access_token`, `qbo_refresh_token`, `two_factor_secret` cast as `encrypted`. Columns widened to TEXT. |
| May 2026 | `RedactSensitiveData` Monolog processor on stack | Anything matching password/token/secret/sort_code/etc keys is logged as `[REDACTED]` |

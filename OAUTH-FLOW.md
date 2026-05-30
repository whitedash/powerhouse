# OAuth Flow for Consumer Apps

Powerhouse is the identity provider for all Whitedash products
(Maavelus, MyOrderPad, etc.). Customers sign in *once* against
Powerhouse and consumer apps trade tokens for their identity.

## Clients

Existing OAuth clients (Passport v12 UUIDs):

| Name                          | client_id (UUID)                       | Redirect URI                          |
|-------------------------------|----------------------------------------|---------------------------------------|
| Whitedash Customer Portal     | `019e6f1a-c524-718f-83b5-001ad023f194` | `http://localhost:8000/account/oauth/callback` |
| Maavelus Control Panel        | `019e6f1a-c6b2-738b-a483-a7a51cb22742` | `https://maavelus.com/oauth/callback` |
| MyOrderPad                    | `019e6f1a-c841-7275-83b5-16f8b9697033` | `https://myorderpad.com/oauth/callback` |

Secrets are server-side only — fetch from `.env`
(`MAAVELUS_OAUTH_SECRET`, etc.). They map to the keys in
`config/services.php → oauth_clients`.

## Scopes

Defined in `AppServiceProvider::configurePassport()`:

- `profile` — basic identity (default scope; always granted)
- `portal` — customer portal access
- `maavelus` — Maavelus restaurant control
- `myorderpad` — MyOrderPad
- `whitedash_portal` — Whitedash client portal

Request multiple by space-separating: `scope=profile+maavelus`.

## Step 1 — Redirect user to authorize

```
GET {POWERHOUSE_URL}/oauth/authorize
    ?client_id={CLIENT_ID}
    &redirect_uri={REDIRECT_URI}
    &response_type=code
    &scope=profile+maavelus
    &state={random_state}
    &code_challenge={PKCE_CHALLENGE}
    &code_challenge_method=S256
```

`code_challenge` is required — Powerhouse enforces PKCE (see
`App\Http\Middleware\RequirePkce`).

The user lands on the branded Powerhouse consent screen.
If they're not signed in to the portal, Passport redirects them
through `/portal/login` first (`passport.guard = 'portal'` in
`config/passport.php`).

## Step 2 — Exchange code for token

After consent, Powerhouse 302s back to the registered redirect URI
with `?code=…&state=…`. The consumer app exchanges the code:

```
POST {POWERHOUSE_URL}/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&client_id={CLIENT_ID}
&client_secret={CLIENT_SECRET}
&redirect_uri={REDIRECT_URI}
&code={CODE_FROM_STEP_1}
&code_verifier={PKCE_VERIFIER}
```

Response:

```json
{
    "access_token": "eyJ...",
    "token_type": "Bearer",
    "expires_in": 1296000,
    "refresh_token": "def..."
}
```

Token TTLs (configurable in `AppServiceProvider::configurePassport`):

- Access token: **15 days**
- Refresh token: **30 days**
- Personal access token: **6 months**

## Step 3 — Get customer info

```
GET {POWERHOUSE_URL}/oauth/userinfo
Authorization: Bearer {ACCESS_TOKEN}
```

Response:

```json
{
    "id": 1,
    "name": "Pitta Republic",
    "email": "info@pittarepublic.co.uk",
    "phone": "+44...",
    "company": "Pitta Republic",
    "products": ["maavelus-hospitality"],
    "maavelus": {
        "customer_product_id": 3,
        "plan": "Professional",
        "status": "active"
    },
    "portal_url": "https://hub.whitedash.com/portal",
    "portal_login_count": 7,
    "portal_last_login_at": "2026-05-29T11:34:21+01:00"
}
```

## Step 4 — Check product access

Lighter endpoint when you only need the access map:

```
GET {POWERHOUSE_URL}/oauth/products
Authorization: Bearer {ACCESS_TOKEN}
```

Response:

```json
{
    "customer_id": 1,
    "products": [
        {
            "slug": "maavelus-hospitality",
            "name": "Maavelus — Hospitality",
            "status": "active",
            "icon_colour": "#7C3AED"
        }
    ]
}
```

## Step 5 — Refresh

```
POST {POWERHOUSE_URL}/oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&refresh_token={REFRESH_TOKEN}
&client_id={CLIENT_ID}
&client_secret={CLIENT_SECRET}
&scope=profile+maavelus
```

## Revocation

End users can revoke a client at any time from
`/portal/dashboard → Connected applications → Revoke`.
That flips `oauth_access_tokens.revoked = true` for every token
issued to any portal user under the customer for that client.

## Rate limits

- `/oauth/userinfo` and `/oauth/products`: **60/min/token**
- `/oauth/authorize` and `/oauth/token`: Passport defaults (no
  per-IP throttle currently; revisit if abuse appears).

## SSO deep link from the portal

Each portal product card includes an `sso_url`:

```
https://restaurant.maavelus.co.uk/?sso=1&customer_id=1
```

The consumer app detects `sso=1` and starts the OAuth flow back
against Powerhouse. The `customer_id` hint is *not* trusted —
the access token after Step 2 is what actually proves identity.

## Local testing

```sh
# 1. Browser-driven authorize page
open 'http://powerhouse.test/oauth/authorize?client_id=019e6f1a-c6b2-738b-a483-a7a51cb22742&redirect_uri=https%3A%2F%2Fmaavelus.com%2Foauth%2Fcallback&response_type=code&scope=profile+maavelus&state=test123&code_challenge=<S256_HASH>&code_challenge_method=S256'

# 2. Exchange the returned ?code=… for a token (curl)
curl -X POST http://powerhouse.test/oauth/token \
  -d 'grant_type=authorization_code' \
  -d 'client_id=019e6f1a-c6b2-738b-a483-a7a51cb22742' \
  -d 'client_secret=$MAAVELUS_OAUTH_SECRET' \
  -d 'redirect_uri=https://maavelus.com/oauth/callback' \
  -d 'code_verifier=<PKCE_VERIFIER>' \
  -d "code=$CODE"

# 3. Hit /oauth/userinfo
curl http://powerhouse.test/oauth/userinfo \
  -H "Authorization: Bearer $ACCESS_TOKEN"
```

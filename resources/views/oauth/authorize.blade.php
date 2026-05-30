{{-- Branded Passport authorize page.
   --
   -- Passport hands us:
   --   $client  — OAuth client (App\Models\Client or compatible) — has ->name, ->id
   --   $user    — authenticated portal user (App\Models\PortalUser) — passport.guard='portal'
   --   $scopes  — array of League\OAuth2 ScopeEntityInterface, each ->getIdentifier()
   --   $request — original auth request (carries client_id, state, redirect_uri, code_challenge...)
   --   $authToken — CSRF-style token Passport requires for POST /oauth/authorize
   --
   -- The form posts to /oauth/authorize (approve) or DELETEs there (deny).
   -- Passport's AuthorizationController completes the response_type=code
   -- flow and 302s back to the consumer's redirect_uri with ?code=&state=. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize {{ $client->name }} · Powerhouse</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f6f7f9;
            color: #1f2937;
            min-height: 100vh;
            display: grid; place-items: center;
            padding: 24px;
        }
        .oauth-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,.04);
            width: 100%;
            max-width: 440px;
            padding: 32px 32px 28px;
        }
        .brand {
            display: flex; align-items: center; justify-content: center;
            gap: 10px;
            margin-bottom: 24px;
        }
        .brand-dot {
            width: 32px; height: 32px;
            border-radius: 8px;
            background: linear-gradient(135deg, #C9A227 0%, #F5C842 100%);
            display: grid; place-items: center;
            color: #111827;
            font-weight: 700; font-size: 14px;
        }
        .brand-name {
            font-weight: 600; font-size: 15px;
            color: #111827;
            letter-spacing: -.01em;
        }
        h1 {
            font-size: 20px; font-weight: 600;
            color: #111827;
            text-align: center;
            margin-bottom: 8px;
            letter-spacing: -.01em;
        }
        .sub {
            color: #6b7280;
            text-align: center;
            font-size: 13.5px;
            line-height: 1.55;
            margin-bottom: 24px;
        }
        .sub strong { color: #111827; font-weight: 600; }

        .user-pill {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .av {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #C9A227;
            color: #fff;
            display: grid; place-items: center;
            font-weight: 600; font-size: 13px;
        }
        .user-pill .meta { display: flex; flex-direction: column; }
        .user-pill .nm { font-weight: 600; font-size: 13.5px; color: #111827; }
        .user-pill .em { color: #6b7280; font-size: 12.5px; }

        .scopes {
            list-style: none;
            margin: 0 0 24px;
            padding: 16px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }
        .scopes-title {
            font-size: 11.5px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .scopes li {
            display: flex; align-items: flex-start; gap: 8px;
            padding: 4px 0;
            font-size: 13.5px;
            color: #374151;
        }
        .scopes svg {
            color: #10b981;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .actions {
            display: flex; gap: 8px;
            margin-bottom: 16px;
        }
        button {
            flex: 1;
            padding: 11px 18px;
            font-size: 14px; font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            transition: background .15s, border-color .15s;
        }
        .btn-primary {
            background: #C9A227;
            color: #111827;
            border: 1px solid #C9A227;
        }
        .btn-primary:hover { background: #B8911F; border-color: #B8911F; }
        .btn-ghost {
            background: #fff;
            color: #6b7280;
            border: 1px solid #e5e7eb;
        }
        .btn-ghost:hover { background: #f9fafb; color: #111827; }

        .foot {
            text-align: center;
            font-size: 12px;
            color: #9ca3af;
            line-height: 1.5;
        }
        .foot a { color: #6b7280; text-decoration: underline; }
    </style>
</head>
<body>
    <main class="oauth-card">
        <div class="brand">
            <div class="brand-dot">P</div>
            <div class="brand-name">Powerhouse</div>
        </div>

        <h1>Authorize {{ $client->name }}</h1>
        <p class="sub">
            <strong>{{ $client->name }}</strong> is requesting access
            to your Powerhouse account.
        </p>

        @php
            $initials = strtoupper(substr($user->first_name ?? $user->email ?? 'U', 0, 1)
                . substr($user->last_name ?? '', 0, 1));
            $displayName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                ?: $user->email;
        @endphp

        <div class="user-pill">
            <div class="av">{{ $initials ?: 'U' }}</div>
            <div class="meta">
                <span class="nm">{{ $displayName }}</span>
                <span class="em">{{ $user->email }}</span>
            </div>
        </div>

        @if (count($scopes) > 0)
            <ul class="scopes">
                <li class="scopes-title">This will allow {{ $client->name }} to</li>
                @foreach ($scopes as $scope)
                    <li>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12l5 5L20 7"/>
                        </svg>
                        {{ $scope->description ?? $scope->getIdentifier() }}
                    </li>
                @endforeach
            </ul>
        @endif

        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">

            <div class="actions">
                <button type="submit" class="btn-primary">Authorize</button>
                <button
                    type="submit"
                    class="btn-ghost"
                    formmethod="post"
                    formaction="{{ route('passport.authorizations.deny') }}"
                    formnovalidate
                >
                    Deny
                </button>
            </div>
        </form>

        {{-- The deny button reaches Passport's DELETE /oauth/authorize via a
             second form below — browsers can't submit a single <form> as DELETE
             without JS. Same CSRF + state set so Passport accepts it. --}}
        <form
            id="deny-form"
            method="post"
            action="{{ route('passport.authorizations.deny') }}"
            style="display: none;"
        >
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
        </form>
        <script>
            // Rewire the Deny button to actually submit the DELETE form
            // above — formmethod="post" alone can't switch the verb.
            document.querySelector('.btn-ghost')?.addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('deny-form').submit();
            });
        </script>

        <p class="foot">
            You can revoke access at any time from your
            <a href="{{ url('/portal/account') }}">portal settings</a>.
        </p>
    </main>
</body>
</html>

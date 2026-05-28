<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce PKCE on the OAuth `authorize` endpoint.
 *
 * Passport 13's `oauth_clients` table has no `code_challenge_method` column,
 * so PKCE can't be required per-client at the DB layer. Instead, we reject
 * any /oauth/authorize request that doesn't carry `code_challenge` plus
 * `code_challenge_method=S256`. Works for both confidential and public
 * clients, defence in depth, no protocol-layer surprises.
 */
class RequirePkce
{
    public function handle(Request $request, Closure $next): Response
    {
        $challenge = $request->input('code_challenge');
        $method = $request->input('code_challenge_method');

        if (! $challenge || $method !== 'S256') {
            abort(400, 'PKCE is required: code_challenge and code_challenge_method=S256 must be present.');
        }

        return $next($request);
    }
}

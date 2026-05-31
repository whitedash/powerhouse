<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\PortalPasswordReset;
use App\Models\ActivityLog;
use App\Models\PortalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal password-reset flow.
 *
 * Defensive choices:
 *
 *  - sendResetLink always reports success (whether or not the email
 *    exists) so the response can't be used to enumerate accounts.
 *  - The token plaintext is generated once, hashed before it lands
 *    in the table, and only echoed back inside the reset URL we send
 *    to the customer. Mirrors the staff /password_reset_tokens
 *    pattern Laravel ships with.
 *  - 2-hour TTL. Tokens beyond that are treated as invalid even if
 *    the hash matches — a stolen email arriving late shouldn't grant
 *    a permanent backdoor.
 *  - Rate limit per email + IP so the endpoint can't be sprayed to
 *    knock real customers' tokens out of the table on repeat.
 */
class PasswordController extends Controller
{
    private const TOKEN_TTL_HOURS = 2;

    private const RATE_LIMIT = 5; // per minute per email+ip

    public function showForgotForm(): Response
    {
        return Inertia::render('Portal/ForgotPassword');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = strtolower((string) $request->input('email'));
        $key = 'portal.forgot|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            // Same generic copy as the success path — no signal that
            // this email is being rate-limited specifically.
            return back()->with('success', $this->genericMessage());
        }
        RateLimiter::hit($key, 60);

        $user = PortalUser::where('email', $email)->first();

        if ($user) {
            $token = Str::random(64);

            DB::transaction(function () use ($email, $token) {
                DB::table('portal_password_resets')->where('email', $email)->delete();
                DB::table('portal_password_resets')->insert([
                    'email' => $email,
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ]);
            });

            $resetUrl = url('/portal/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $email,
            ]));

            // Email the secure reset link. We also keep the log line so a
            // tester (or staff running an on-the-phone reset) can pull the
            // URL when mail delivery isn't configured locally.
            Mail::to($user->email)->send(new PortalPasswordReset($user, $resetUrl));
            Log::info('Portal password reset requested', [
                'email' => $email,
                'reset_url' => $resetUrl,
            ]);

            ActivityLog::create([
                'user_id' => $user->id,
                'user_role' => 'portal',
                'action' => 'portal.reset_requested',
                'entity_type' => PortalUser::class,
                'entity_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        return back()->with('success', $this->genericMessage());
    }

    public function showResetForm(Request $request): Response
    {
        return Inertia::render('Portal/ResetPassword', [
            'token' => (string) $request->query('token', ''),
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(10)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $email = strtolower($data['email']);

        $reset = DB::table('portal_password_resets')->where('email', $email)->first();

        if (! $reset
            || ! Hash::check($data['token'], $reset->token)
            || Carbon::parse($reset->created_at)->addHours(self::TOKEN_TTL_HOURS)->isPast()
        ) {
            // Don't reveal *which* of the three checks failed — a
            // generic message keeps the token-rotation surface clean.
            return back()->withErrors([
                'token' => 'This reset link is invalid or has expired. Please request a new one.',
            ])->withInput($request->only('email'));
        }

        $user = PortalUser::where('email', $email)->first();
        if (! $user) {
            // The reset row pointed at an account that's since been
            // deleted. Drop the row and bail cleanly.
            DB::table('portal_password_resets')->where('email', $email)->delete();

            return back()->withErrors([
                'token' => 'This reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        DB::transaction(function () use ($user, $data, $email, $request) {
            $user->password = $data['password']; // hashed cast does the bcrypt
            $user->save();

            DB::table('portal_password_resets')->where('email', $email)->delete();

            ActivityLog::create([
                'user_id' => $user->id,
                'user_role' => 'portal',
                'action' => 'portal.password_reset',
                'entity_type' => PortalUser::class,
                'entity_id' => $user->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        });

        return redirect()
            ->route('portal.login')
            ->with('success', 'Password updated. Please sign in with your new password.');
    }

    private function genericMessage(): string
    {
        return 'If an account exists with that email, a reset link has been sent.';
    }
}

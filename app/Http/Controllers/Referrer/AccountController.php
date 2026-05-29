<?php

namespace App\Http\Controllers\Referrer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Referrer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service profile + bank-detail editor for referrers.
 *
 * Payment details are stored encrypted at rest via the
 * 'encrypted:array' cast on Referrer::$payment_details. We
 * deliberately do NOT echo full bank details back to the frontend;
 * the response carries only the last 4 digits of the account
 * number and a "set / not set" indicator so a compromised session
 * cookie can't be used to scrape full account info.
 */
class AccountController extends Controller
{
    public function index(): Response
    {
        $user = Auth::user();
        $referrer = Referrer::where('user_id', $user->id)->firstOrFail();

        $payment = $referrer->payment_details ?: [];

        return Inertia::render('Referrer/Account', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'last_login_at' => $user->last_login_at?->diffForHumans(),
            ],
            'payment_summary' => [
                'has_details' => ! empty($payment['account_number'] ?? null),
                'bank_name' => $payment['bank_name'] ?? null,
                'account_name' => $payment['account_name'] ?? null,
                'sort_code' => $payment['sort_code'] ?? null,
                // Mask everything but the last 4 digits — the UI shows
                // "•••• 1234" rather than the raw account number.
                'account_number_last4' => isset($payment['account_number'])
                    ? substr((string) $payment['account_number'], -4)
                    : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $before = ['name' => $user->name, 'email' => $user->email];

        $user->forceFill($data)->save();

        ActivityLog::create([
            'user_id' => $user->id,
            'user_role' => 'referrer',
            'action' => 'referrer.profile_updated',
            'entity_type' => $user::class,
            'entity_id' => $user->id,
            'before' => $before,
            'after' => $data,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That current password isn\'t right.',
            ]);
        }

        $user->password = Hash::make($data['password']);
        $user->save();

        ActivityLog::create([
            'user_id' => $user->id,
            'user_role' => 'referrer',
            'action' => 'referrer.password_changed',
            'entity_type' => $user::class,
            'entity_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Password updated.');
    }

    public function updatePayment(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $referrer = Referrer::where('user_id', $user->id)->firstOrFail();

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            // UK sort code: 6 digits with optional hyphens. The cast
            // accepts both; we normalise to digits-only on store.
            'sort_code' => ['required', 'string', 'regex:/^[0-9\-\s]{6,8}$/'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{6,12}$/'],
        ]);

        $normalised = [
            'bank_name' => $data['bank_name'],
            'account_name' => $data['account_name'],
            'sort_code' => preg_replace('/[^0-9]/', '', $data['sort_code']),
            'account_number' => $data['account_number'],
        ];

        $referrer->payment_details = $normalised;
        $referrer->save();

        // Activity log records the *fact* of the update only — never
        // the bank details themselves. This is deliberate: ActivityLog
        // is plaintext JSON and we don't want it to become a side
        // channel for the data we just encrypted.
        ActivityLog::create([
            'user_id' => $user->id,
            'user_role' => 'referrer',
            'action' => 'referrer.payment_details_updated',
            'entity_type' => Referrer::class,
            'entity_id' => $referrer->id,
            'after' => ['account_number_last4' => substr($normalised['account_number'], -4)],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()->with('success', 'Payment details saved.');
    }
}

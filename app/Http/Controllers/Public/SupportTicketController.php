<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportTicketRequest;
use App\Services\TicketIntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public submit-ticket flow at /support. Unauthenticated; protected by a
 * honeypot, Cloudflare Turnstile, and a route throttle. Delegates the
 * actual creation to TicketIntakeService (shared with the create_ticket
 * workflow action).
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly TicketIntakeService $intake) {}

    public function create(): Response
    {
        return Inertia::render('Public/Support/New', [
            'turnstileSiteKey' => config('services.turnstile.site_key'),
        ]);
    }

    public function store(StoreSupportTicketRequest $request): RedirectResponse
    {
        // Honeypot: a hidden field humans never touch. If a bot filled it,
        // respond as if accepted but create nothing — don't tip them off.
        if ($request->filled('_hp')) {
            return redirect()->route('support.submitted');
        }

        if (! $this->turnstilePasses($request)) {
            return back()
                ->withInput()
                ->withErrors(['captcha' => 'Verification failed — please try again.']);
        }

        $data = $request->validated();

        $ticket = $this->intake->create([
            'subject' => $data['subject'],
            'message' => $data['message'],
            'priority' => 'medium',
            'guest_name' => $data['guest_name'],
            'guest_email' => $data['guest_email'],
            'guest_phone' => $data['guest_phone'] ?? null,
        ], 'web');

        // Reference passed via flash so /support/submitted can't be enumerated.
        return redirect()->route('support.submitted')->with('ticket_ref', $ticket->id);
    }

    public function submitted(Request $request): Response|RedirectResponse
    {
        $ref = $request->session()->get('ticket_ref');
        if ($ref === null) {
            return redirect()->route('support.create');
        }

        return Inertia::render('Public/Support/Submitted', [
            'reference' => $ref,
        ]);
    }

    /**
     * Verify the Turnstile token server-side. Bypassed only when
     * services.turnstile.bypass is true (testing / keyless local dev);
     * production never bypasses.
     */
    private function turnstilePasses(Request $request): bool
    {
        if (config('services.turnstile.bypass')) {
            return true;
        }

        $token = (string) $request->input('cf-turnstile-response');
        if ($token === '') {
            return false;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('services.turnstile.secret'),
            'response' => $token,
            'remoteip' => $request->ip(),
        ]);

        return $response->ok() && ($response->json('success') === true);
    }
}

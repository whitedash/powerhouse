<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns the JavaScript embed widget for a form.
 *
 * GET /forms/{slug}/embed.js  — serves application/javascript.
 *
 * Use case: a site embeds <script src="hub.whitedash.co.uk/forms/contact-us/embed.js">
 * which finds <div id="pw-form-contact-us"></div> and renders
 * the form inside it. The script then posts back to
 * /forms/{slug}/submit via fetch().
 *
 * Caching: 5 minutes is a deliberate compromise — long enough
 * to absorb the per-page-load traffic, short enough that a
 * form edit shows up to existing embedders without bouncing
 * their site. Browsers / CDNs will revalidate ~once per visit
 * during a session.
 *
 * CORS: we set Access-Control-Allow-Origin to "*" because the
 * embed script is intended for arbitrary third-party sites.
 * The submit endpoint similarly handles cross-origin POSTs
 * (CSRF is excluded for the public form routes in
 * bootstrap/app.php).
 */
class EmbedController extends Controller
{
    public function script(string $slug, Request $request): Response
    {
        $form = Form::where('slug', $slug)
            ->where('status', 'active')
            ->with('fields')
            ->firstOrFail();

        $js = view('embed.form-widget', [
            'form' => $form,
            'submit_url' => rtrim((string) config('app.url'), '/').'/forms/'.$form->slug.'/submit',
        ])->render();

        return response($js)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

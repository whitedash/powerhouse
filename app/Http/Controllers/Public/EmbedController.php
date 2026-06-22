<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormField;
use App\Services\FormContentSanitizer;
use App\Support\FormThemeTokens;
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
            ->with(['fields', 'steps', 'theme'])
            ->firstOrFail();

        // XSS boundary: the widget assigns placeholder field content straight to
        // innerHTML on arbitrary third-party (cross-origin) pages. Sanitise it
        // server-side here with the same allow-list as the save path — this also
        // cleans any row persisted before the save-time sanitiser existed. The
        // models are mutated in memory only (no save); the view reads them.
        $sanitizer = app(FormContentSanitizer::class);
        $form->fields->each(function (FormField $field) use ($sanitizer): void {
            if ($field->type === 'placeholder') {
                $field->content = $sanitizer->sanitize($field->content);
            }
        });

        // Resolve the form's effective design tokens through the single
        // source of truth: the default token set (today's look) for an
        // un-themed form, or defaults merged with the theme's overrides.
        $tokens = FormThemeTokens::resolve($form->theme);

        $js = view('embed.form-widget', [
            'form' => $form,
            'submit_url' => rtrim((string) config('app.url'), '/').'/forms/'.$form->slug.'/submit',
            'tokens' => $tokens,
        ])->render();

        return response($js)
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300')
            ->header('Access-Control-Allow-Origin', '*')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}

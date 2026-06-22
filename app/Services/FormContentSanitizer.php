<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Server-side allow-list sanitiser for form "placeholder" (text-block) field
 * content (form_fields.content).
 *
 * The allow-list mirrors exactly what FormFieldRenderer.vue renders via its
 * DOMPurify config — tags p, br, strong, em, u, ul, ol, li, a; href/target on
 * anchors (rel forced safe); http(s)/mailto link schemes — so the server and
 * client agree on the safe subset. Everything else (script, event handlers,
 * style, img, iframe, etc.) is stripped.
 *
 * Applied on SAVE (FormBuilderController) so stored content is always clean, and
 * again when the public embed widget is SERVED (EmbedController) so rows saved
 * before this sanitiser existed — and the cross-origin innerHTML sink in
 * embed/form-widget.blade.php — are covered too. Reuses symfony/html-sanitizer,
 * the same library KbContentService uses (with a stricter, form-specific list).
 */
class FormContentSanitizer
{
    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return $this->sanitizer()->sanitize($html);
    }

    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            // Exactly the tags FormFieldRenderer's DOMPurify allow-list renders.
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('a', ['href', 'target'])
            // Only navigable schemes; drops javascript:, data:, etc.
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            // Always harden anchors regardless of what the author supplied.
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer');

        return new HtmlSanitizer($config);
    }
}

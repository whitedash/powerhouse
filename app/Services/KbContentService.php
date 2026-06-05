<?php

namespace App\Services;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Renders knowledge-base article bodies to HTML.
 *
 * Article `content` is dual-format: rich-text-editor articles are stored as
 * HTML (body starts with a tag), older articles as Markdown. render() mirrors
 * the staff HelpController detection exactly. renderSafe() additionally runs
 * the output through symfony/html-sanitizer with a strict allow-list — used on
 * the PUBLIC /kb pages, where the rendered HTML is the XSS boundary.
 *
 * (Staff Help still uses its own private renderer for now; unifying it onto
 * this service is a tracked follow-up.)
 */
class KbContentService
{
    /**
     * Detect HTML vs Markdown and return HTML. Markdown is converted with
     * raw-HTML escaped and unsafe links disabled. HTML bodies are returned
     * as-is (sanitisation, when needed, is renderSafe()'s job).
     */
    public function render(string $body): string
    {
        $trimmed = ltrim($body);
        if ($trimmed !== '' && str_starts_with($trimmed, '<')) {
            return $body;
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($body);
    }

    /**
     * render() + allow-list sanitisation. Safe to feed to v-html on a public
     * page: scripts, event handlers, styles, iframes and unsafe link schemes
     * are stripped; links are forced to open safely.
     */
    public function renderSafe(string $body): string
    {
        return $this->sanitizer()->sanitize($this->render($body));
    }

    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig())
            // W3C-sanitizer safe baseline: text formatting, lists, headings,
            // blockquotes, code/pre, tables, links, images — no script/style/
            // iframe/object and no on* event-handler attributes.
            ->allowSafeElements()
            // Only navigable schemes; drops javascript:, data:, etc.
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            // Harden every surviving anchor.
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank');

        return new HtmlSanitizer($config);
    }
}

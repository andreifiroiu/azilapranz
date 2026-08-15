<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\Log;

/**
 * Sanitiser for HTML that came out of the legacy CKEditor admin.
 *
 * The stored markup is a mix of hand-written formatting, Word paste residue
 * (`class="MsoNormal"`, `style="font-size: 14pt"`) and — in at least one CMS
 * page — dead third-party widget scripts loaded over plain http. None of it can
 * be trusted to output raw, and none of it needs anything beyond basic
 * formatting, so everything is rebuilt from an allowlist.
 */
class LegacyHtml
{
    /** Tags kept, with their attributes dropped unless listed in ATTRIBUTES. */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4',
        'a', 'blockquote',
        'table', 'thead', 'tbody', 'tr', 'td', 'th',
    ];

    /**
     * Tags dropped together with their contents.
     *
     * The second row are raw-text elements: libxml parses their contents into a
     * single CDATA node rather than into elements, and saveHTML() writes CDATA
     * back out verbatim without escaping. Left in place they smuggle live
     * markup straight past the element allowlist — <noframes><script>…
     * </script></noframes> emitted a working script tag.
     */
    private const VOID_CONTENT_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'textarea', 'noscript',
        'noembed', 'noframes', 'xmp', 'plaintext', 'listing', 'template',
    ];

    /** The only attributes that survive, per tag. */
    private const ATTRIBUTES = [
        'a' => ['href'],
    ];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        // Plain text (no markup at all) just needs escaping and line breaks.
        if (! preg_match('/<[a-zA-Z\/!]/', $html)) {
            return nl2br(e($html), false);
        }

        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        // The XML declaration is what makes libxml read the string as UTF-8.
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="azp-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('azp-root');

        if (! $root) {
            // Degrading every tag away is a real loss of formatting, and it
            // would happen site-wide if a libxml upgrade changed getElementById
            // behaviour here. Silent degradation at HTTP 200 is undetectable,
            // so say something.
            Log::warning('LegacyHtml could not locate its wrapper; falling back to stripped text', [
                'length' => strlen($html),
                'excerpt' => mb_substr($html, 0, 120),
            ]);

            return nl2br(e(strip_tags($html)), false);
        }

        self::sanitiseChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return self::tidySpacing($out);
    }

    /**
     * Legacy rows carry long runs of empty <br /> used as vertical spacing,
     * separated by whitespace and non-breaking spaces. Note \s does not match
     * U+00A0, which is why it is listed explicitly.
     */
    private static function tidySpacing(string $html): string
    {
        $gap = '(?:\s|&nbsp;|\x{00A0})*';
        $br = "(?:{$gap}<br\s*/?>{$gap})";

        // Runs of breaks collapse to one. A paragraph break is not safe here:
        // the content may never have opened a paragraph to close.
        $html = preg_replace("#{$br}{2,}#iu", '<br>', $html);

        // Paragraphs holding nothing but spacing.
        $html = preg_replace("#<p>(?:{$gap}|<br\s*/?>)*</p>#iu", '', $html);

        // Leading and trailing spacing breaks contribute nothing.
        $html = preg_replace("#^{$br}+#iu", '', $html);
        $html = preg_replace("#{$br}+$#iu", '', $html);

        return trim(preg_replace("/^{$gap}|{$gap}$/u", '', $html));
    }

    private static function sanitiseChildren(DOMNode $node): void
    {
        // Snapshot first: the loop rewrites the live child list.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            // Comments and CDATA are serialized verbatim by saveHTML(), so
            // skipping every non-element node would let them through unescaped.
            // Only text nodes are safe to leave alone.
            if (! $child instanceof DOMElement) {
                if (in_array($child->nodeType, [XML_COMMENT_NODE, XML_CDATA_SECTION_NODE], true)) {
                    $child->parentNode?->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::VOID_CONTENT_TAGS, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            self::sanitiseChildren($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::unwrap($child);

                continue;
            }

            self::stripAttributes($child, $tag);
        }
    }

    /** Replace an element with its children, keeping the text. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private static function stripAttributes(DOMElement $element, string $tag): void
    {
        $keep = self::ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), $keep, true)) {
                $element->removeAttribute($attribute->nodeName);
            }
        }

        if ($tag !== 'a') {
            return;
        }

        $href = trim($element->getAttribute('href'));

        if ($href === '' || ! self::isSafeUrl($href)) {
            self::unwrap($element);

            return;
        }

        $element->setAttribute('href', $href);
        // Outbound links carried rel="nofollow" on the legacy site.
        $element->setAttribute('rel', 'nofollow noopener');
        $element->setAttribute('target', '_blank');
    }

    private static function isSafeUrl(string $url): bool
    {
        // "//evil.test/x" also starts with "/" but is protocol-relative — an
        // off-site link the allowlist would otherwise treat as internal.
        if (str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, self::SAFE_SCHEMES, true);
    }
}

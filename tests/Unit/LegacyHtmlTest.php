<?php

namespace Tests\Unit;

use App\Support\LegacyHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LegacyHtmlTest extends TestCase
{
    public function test_it_keeps_basic_formatting(): void
    {
        $out = LegacyHtml::clean('<p>Meniul zilei se serveste <strong>zilnic</strong>.</p>');

        $this->assertSame('<p>Meniul zilei se serveste <strong>zilnic</strong>.</p>', $out);
    }

    public function test_it_escapes_plain_text_and_keeps_line_breaks(): void
    {
        $out = LegacyHtml::clean("Bine ati venit\nla noi & la masa");

        $this->assertStringContainsString('<br>', $out);
        $this->assertStringContainsString('&amp;', $out);
    }

    /**
     * Once the content carries real markup, newlines are insignificant
     * whitespace — the same as any HTML document.
     */
    public function test_it_does_not_add_breaks_to_marked_up_content(): void
    {
        $out = LegacyHtml::clean("<p>\n\tMeniul zilei se serveste zilnic.</p>\n");

        // Insignificant whitespace may survive; a spurious <br> may not.
        $this->assertStringNotContainsString('<br', $out);
        $this->assertStringContainsString('Meniul zilei se serveste zilnic.', $out);
    }

    public function test_it_drops_scripts_with_their_contents(): void
    {
        $out = LegacyHtml::clean(
            '<p>Salut</p><script type="text/javascript" src="http://platform.twitter.com/widgets.js"></script>'
        );

        $this->assertSame('<p>Salut</p>', $out);
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('twitter', $out);
    }

    public function test_it_drops_iframes_and_forms(): void
    {
        $out = LegacyHtml::clean('<p>a</p><iframe src="http://evil.test"></iframe><form><input name="x"></form>');

        $this->assertStringNotContainsString('iframe', $out);
        $this->assertStringNotContainsString('<form', $out);
        $this->assertStringNotContainsString('<input', $out);
    }

    /**
     * Raw-text elements parse into a single CDATA node rather than into
     * elements, and saveHTML() writes CDATA back verbatim. Skipping non-element
     * nodes therefore smuggled live markup past the allowlist entirely.
     *
     * @param  string  $payload  markup that must not survive
     */
    #[DataProvider('rawTextElementPayloads')]
    public function test_raw_text_elements_cannot_smuggle_markup(string $payload): void
    {
        $out = LegacyHtml::clean($payload);

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringNotContainsString('<svg', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringNotContainsString('onload', $out);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public static function rawTextElementPayloads(): array
    {
        return [
            'noembed' => ['<p>a</p><noembed><img src=x onerror="alert(1)"></noembed><p>b</p>'],
            'noframes' => ['<noframes><script>alert(1)</script></noframes>'],
            'xmp' => ['<xmp><svg onload=alert(1)></xmp>'],
            'plaintext' => ['<plaintext><img src=x onerror=alert(1)>'],
            'listing' => ['<listing><script>alert(1)</script></listing>'],
            'noembed link' => ['<noembed><a href="javascript:alert(1)">z</a></noembed>'],
            'nested' => ['<div><noembed><script>alert(1)</script></noembed></div>'],
        ];
    }

    public function test_it_keeps_surrounding_content_when_dropping_raw_text_elements(): void
    {
        $out = LegacyHtml::clean('<p>Bun venit</p><noembed><img src=x></noembed><p>la noi</p>');

        $this->assertSame('<p>Bun venit</p><p>la noi</p>', $out);
    }

    public function test_it_drops_conditional_comments(): void
    {
        $out = LegacyHtml::clean('<p>a</p><!--[if IE]><script>alert(1)</script><![endif]-->');

        $this->assertSame('<p>a</p>', $out);
    }

    public function test_it_strips_word_paste_attributes(): void
    {
        $out = LegacyHtml::clean('<p class="MsoNormal"><span style="font-size: 14pt;">Text</span></p>');

        $this->assertSame('<p>Text</p>', $out);
    }

    public function test_it_strips_event_handler_attributes(): void
    {
        $out = LegacyHtml::clean('<p onclick="steal()" onmouseover="x()">Text</p>');

        $this->assertSame('<p>Text</p>', $out);
    }

    public function test_it_keeps_safe_links_and_marks_them_nofollow(): void
    {
        $out = LegacyHtml::clean('<a href="http://restaurantinfinit.ro/Meniu/">Meniu</a>');

        $this->assertStringContainsString('href="http://restaurantinfinit.ro/Meniu/"', $out);
        $this->assertStringContainsString('rel="nofollow noopener"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
    }

    public function test_it_unwraps_links_with_unsafe_schemes(): void
    {
        $out = LegacyHtml::clean('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringContainsString('Click', $out);
    }

    /** "//evil.test/x" starts with "/" but is an off-site link, not a relative one. */
    public function test_it_unwraps_protocol_relative_links(): void
    {
        $out = LegacyHtml::clean('<a href="//evil.test/x">Click</a>');

        $this->assertStringNotContainsString('evil.test', $out);
        $this->assertStringContainsString('Click', $out);
    }

    public function test_it_unwraps_disallowed_tags_but_keeps_their_text(): void
    {
        $out = LegacyHtml::clean('<p><span>Bucatar din <font size="3">Serbia</font></span></p>');

        $this->assertSame('<p>Bucatar din Serbia</p>', $out);
    }

    public function test_it_collapses_long_runs_of_empty_breaks(): void
    {
        $out = LegacyHtml::clean("Livrari la domiciliu<br />\n\t <br />\n<br />\n<br />\n\t <br />");

        $this->assertSame('Livrari la domiciliu', $out);
    }

    /**
     * The real rows separate their trailing breaks with U+00A0, which \s does
     * not match — the collapse has to name it explicitly.
     */
    public function test_it_collapses_breaks_separated_by_non_breaking_spaces(): void
    {
        $nbsp = "\u{00A0}";
        $out = LegacyHtml::clean("Orar: 12:00-23:00<br />\n\t{$nbsp}<br />{$nbsp}<br />\n\t{$nbsp}");

        $this->assertSame('Orar: 12:00-23:00', $out);
    }

    /** A collapse must never emit a </p> for a paragraph that never opened. */
    public function test_collapsing_breaks_keeps_paragraph_tags_balanced(): void
    {
        $out = LegacyHtml::clean('Text<br /><br /><br />mai mult text');

        $this->assertSame(
            substr_count(strtolower($out), '<p>'),
            substr_count(strtolower($out), '</p>')
        );
    }

    public function test_it_preserves_diacritics(): void
    {
        $out = LegacyHtml::clean('<p>Bucătărie tradițională românească</p>');

        $this->assertStringContainsString('Bucătărie tradițională românească', $out);
    }

    public function test_it_handles_empty_input(): void
    {
        $this->assertSame('', LegacyHtml::clean(null));
        $this->assertSame('', LegacyHtml::clean(''));
        $this->assertSame('', LegacyHtml::clean('   '));
    }

    public function test_it_keeps_lists_and_tables(): void
    {
        $out = LegacyHtml::clean('<ul><li>Supa</li><li>Fel principal</li></ul>');

        $this->assertSame('<ul><li>Supa</li><li>Fel principal</li></ul>', $out);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The GA4 tag and the consent bar that governs it.
 *
 * Consent Mode is an ordering contract before it is anything else. The
 * `consent default` command has to reach the dataLayer before `config`, and the
 * async loader has to come after both — otherwise the first page_view of every
 * visit is collected with storage wide open, which is the one failure this
 * whole feature exists to prevent. That ordering is invisible in review and
 * silent in production, so it is what these tests pin.
 *
 * Both the tag and the bar hang off `azp.analytics_id`, which phpunit.xml pins
 * empty. The default state here is therefore an untagged page, and every test
 * that wants the tag opts in with config()->set(), the same way
 * PlaceDetailsWidgetTest opts into the Places UI Kit.
 *
 * What this file cannot cover, and does not pretend to: everything after the
 * click. The `consent update` that Accept fires, the cookie write, the six-month
 * expiry, the bar unhiding for a first-time visitor and staying hidden for a
 * returning one, the _ga sweep on withdrawal, the focus move when the footer
 * reopens it — all of that is browser behaviour, and there is no browser in this
 * suite. The JavaScript in components/analytics.blade.php and
 * components/cookie-banner.blade.php is untested. Change it by hand, in a
 * browser, with the console open.
 */
class CookieConsentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Accept and Refuz must be indistinguishable — refusing has to be as easy
     * as accepting, and that starts with the two looking the same. One shared
     * class string, asserted twice, is the cheapest way to notice someone
     * "improving" one of them.
     */
    private const BUTTON_CLASSES = 'class="cursor-pointer rounded border border-ink px-5 py-2 text-sm font-medium transition-colors hover:bg-ink hover:text-paper"';

    private function withAnalytics(): void
    {
        config()->set('azp.analytics_id', 'G-TEST123');
    }

    /** Substring assertion against HTML we already hold. */
    private function assertHtmlContains(string $html, string $needle): void
    {
        $this->assertStringContainsString($needle, $html);
    }

    /** Byte offset of an exact fragment, failing loudly if it is absent. */
    private function positionOf(string $html, string $needle): int
    {
        $position = strpos($html, $needle);

        $this->assertNotFalse($position, "Expected the page to contain: {$needle}");

        return $position;
    }

    public function test_the_consent_defaults_are_queued_before_the_config_call(): void
    {
        $this->withAnalytics();

        $html = $this->get('/')->assertOk()->getContent();

        $default = $this->positionOf($html, "gtag('consent', 'default', {");
        $config = $this->positionOf($html, 'gtag(\'config\', "G-TEST123");');
        $loader = $this->positionOf(
            $html,
            '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST123"></script>'
        );

        $this->assertLessThan($config, $default,
            'consent default must reach the dataLayer before config, or the first page_view is collected with storage open.');

        $this->assertLessThan($loader, $config,
            'The async loader must sit below the inline block; above it, it can execute first and process config with no consent state queued.');
    }

    public function test_a_stored_decision_is_replayed_above_the_config_call(): void
    {
        $this->withAnalytics();

        $html = $this->get('/')->assertOk()->getContent();

        $reader = $this->positionOf($html, 'window.azpConsent = window.azpReadConsent();');
        $update = $this->positionOf($html, "gtag('consent', 'update', { analytics_storage: 'granted' });");
        $config = $this->positionOf($html, 'gtag(\'config\', "G-TEST123");');

        // Without the read there is no decision to replay, and the banner's
        // `if (!window.azpConsent)` would show the bar to everyone on every
        // page. Asserting the update alone would stay green through that.
        $this->assertLessThan($update, $reader,
            'The stored decision must be read before it can be replayed.');

        $this->assertLessThan($config, $update,
            'A returning visitor who accepted must be granted before config, or their first hit of the visit is measured as denied.');
    }

    public function test_every_storage_type_starts_denied(): void
    {
        $this->withAnalytics();

        $this->get('/')
            ->assertOk()
            ->assertSee("analytics_storage: 'denied',", false)
            ->assertSee("ad_storage: 'denied',", false)
            ->assertSee("ad_user_data: 'denied',", false)
            ->assertSee("ad_personalization: 'denied',", false);
    }

    public function test_the_banner_ships_hidden_and_offers_both_answers(): void
    {
        $this->withAnalytics();

        $this->get('/')
            ->assertOk()
            // The hidden attribute is the flash guard: the bar is revealed by
            // script only when no decision is stored, never the other way round.
            ->assertSee('<section id="consimtamant-cookie" hidden tabindex="-1"', false)
            ->assertSee('aria-label="Consimțământ pentru cookie-uri"', false)
            ->assertSee('<button type="button" id="consimtamant-accept"', false)
            ->assertSee('<button type="button" id="consimtamant-refuz"', false)
            // It does not block the page, so it must not claim to be modal.
            ->assertDontSee('role="dialog"', false)
            ->assertDontSee('aria-modal', false);
    }

    public function test_both_answers_are_styled_identically(): void
    {
        $this->withAnalytics();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, self::BUTTON_CLASSES),
            'Accept and Refuz must carry identical styling.');
    }

    public function test_the_banner_links_the_existing_privacy_page(): void
    {
        $this->withAnalytics();

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.url('/politica-de-confidentialitate.html').'">politica de confidențialitate</a>', false);
    }

    public function test_the_footer_carries_a_hidden_control_to_reopen_it(): void
    {
        $this->withAnalytics();

        $this->get('/')
            ->assertOk()
            // Withdrawal has to be as easy as consent (GDPR art. 7(3)).
            ->assertSee('<button type="button" id="consimtamant-setari"', false)
            ->assertSee('Setări cookie-uri')
            // Hidden until the banner's script reveals it, so it is never a dead
            // control for a visitor without JavaScript.
            ->assertSee('<li hidden>', false);
    }

    public function test_the_privacy_page_describes_the_cookies_it_asks_about(): void
    {
        $this->withAnalytics();

        // Consent has to be informed, and the legacy row behind this URL only
        // covers the Facebook bot.
        $this->get('/politica-de-confidentialitate.html')
            ->assertOk()
            // Fragments unique to this block. `azp_consent` and
            // `Setări cookie-uri` would not do: the first is in the head regex
            // and the second in the footer, so both appear on every page and
            // would pass here with the whole section deleted.
            ->assertSee('<h2>Cookie-uri</h2>', false)
            ->assertSee('<h3>Cookie-uri strict necesare</h3>', false)
            ->assertSee('<h3>Cum îți retragi acordul</h3>', false)
            ->assertSee('Google Analytics 4');
    }

    public function test_the_reader_and_the_writer_agree_on_the_cookie(): void
    {
        $this->withAnalytics();

        $html = $this->get('/')->assertOk()->getContent();
        $consent = config('azp.consent');

        // config/azp.php warns that these must agree "or consent is written
        // under one key and read under another — which fails silently and looks
        // like the banner comes back on every page". Nothing else pins it:
        // desyncing the two halves leaves every other test in this file green.
        // Both values are read from config here so a deliberate change moves the
        // assertion with the code, and an accidental one-sided edit fails.
        $cookie = $consent['cookie'];
        $version = (int) $consent['version'];

        // The reader (head) and the writer (banner), each rendered from config.
        $this->assertHtmlContains($html, 'var name = "'.$cookie.'".replace(');
        $this->assertHtmlContains($html, 'var cookieName = "'.$cookie.'";');
        $this->assertHtmlContains($html, "if (parts[0] !== '".$version."') return null;");
        $this->assertHtmlContains($html, 'var version = '.$version.';');
    }

    public function test_the_footer_control_matches_the_id_the_script_queries(): void
    {
        $this->withAnalytics();

        $html = $this->get('/')->assertOk()->getContent();

        // Renaming the id in the layout alone fails the footer test above, but
        // renaming it in the script alone would leave the whole suite green and
        // silently delete the GDPR art. 7(3) withdrawal path: the <li> stays
        // hidden forever and the bar can never be reopened.
        $this->assertHtmlContains($html, 'id="consimtamant-setari"');
        $this->assertHtmlContains($html, "getElementById('consimtamant-setari')");
    }

    public function test_the_privacy_copy_states_the_configured_cookie_and_lifetime(): void
    {
        $this->withAnalytics();

        // The copy is a published legal statement; retyping these would let a
        // config change make it quietly untrue.
        $this->get('/politica-de-confidentialitate.html')
            ->assertOk()
            ->assertSee('<strong>'.config('azp.consent.cookie').'</strong>', false)
            ->assertSee('Durează 6 luni.');
    }

    public function test_the_banner_reaches_the_city_pages_too(): void
    {
        $this->withAnalytics();

        $this->get('/timisoara/restaurante.html')
            ->assertOk()
            ->assertSee('<section id="consimtamant-cookie" hidden tabindex="-1"', false);
    }

    public function test_no_tag_and_no_banner_without_a_measurement_id(): void
    {
        // phpunit.xml pins AZP_ANALYTICS_ID empty; this is the default state.
        // Nothing to measure means nothing to consent to, and a banner asking
        // permission for nothing would be worse than no banner.
        $this->get('/')
            ->assertOk()
            ->assertDontSee('gtag', false)
            ->assertDontSee('googletagmanager', false)
            ->assertDontSee('consimtamant-cookie', false)
            ->assertDontSee('Setări cookie-uri');
    }

    public function test_the_privacy_page_stays_quiet_without_a_measurement_id(): void
    {
        $this->get('/politica-de-confidentialitate.html')
            ->assertOk()
            ->assertDontSee('<h2>Cookie-uri</h2>', false)
            ->assertDontSee('azp_consent');
    }
}

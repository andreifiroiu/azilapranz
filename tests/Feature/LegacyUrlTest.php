<?php

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The status-code and canonical contract for every legacy URL family.
 *
 * A regression here means an indexed URL changed behaviour, so these assertions
 * are deliberately specific about status codes and redirect targets.
 */
class LegacyUrlTest extends TestCase
{
    use RefreshDatabase;

    private function venue(array $overrides = []): Location
    {
        return Location::create(array_merge([
            'id' => 560,
            'name' => 'Al Duomo',
            'slug' => 'restaurant-al-duomo-560',
            'city' => 'Timisoara',
            'city_slug' => 'timisoara',
            'type' => 'restaurant',
            'area' => 'Centru',
            'address' => 'Str Paul Chinezu, nr 2',
            'phone' => '0256.437.199',
            'status' => 'active',
        ], $overrides));
    }

    public function test_the_homepage_keeps_its_legacy_title(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Meniul zilei Timisoara - AziLaPranz.ro</title>', false);
    }

    public function test_a_venue_page_reproduces_the_legacy_title_and_h1(): void
    {
        $this->venue();

        $response = $this->get('/timisoara/restaurant-al-duomo-560.html');

        $response->assertOk()
            ->assertSee('<title>Restaurant Al Duomo, Timisoara - AziLaPranz.ro</title>', false)
            ->assertSee('<h1 class="mt-2 font-display text-4xl font-bold tracking-tight sm:text-5xl">', false)
            ->assertSee('Al Duomo')
            ->assertSee('Telefon: 0256.437.199, Adresa: Str Paul Chinezu, nr 2', false);
    }

    public function test_a_venue_page_declares_a_canonical_and_restaurant_schema(): void
    {
        $this->venue();

        $this->get('/timisoara/restaurant-al-duomo-560.html')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/timisoara/restaurant-al-duomo-560.html').'">', false)
            ->assertSee('"@type":"Restaurant"', false)
            ->assertSee('"@type":"BreadcrumbList"', false);
    }

    /**
     * JSON-LD is emitted inside a <script> block, and JSON_UNESCAPED_SLASHES
     * means a "</script>" in venue data would otherwise close the tag early and
     * let the rest execute. JSON_HEX_TAG escapes < and > to < / >.
     */
    public function test_venue_data_cannot_break_out_of_the_json_ld_script(): void
    {
        $this->venue(['name' => 'Pizza </script><script>alert(1)</script>']);

        $response = $this->get('/timisoara/restaurant-al-duomo-560.html');

        $response->assertOk()
            ->assertDontSee('</script><script>alert(1)', false)
            ->assertSee('</script>', false);
    }

    public function test_the_multi_value_type_widens_the_title(): void
    {
        $this->venue([
            'id' => 36,
            'name' => 'Al Pacino',
            'slug' => 'restaurant-pizzerie-catering-al-pacino-36',
            'type' => 'restaurant,pizzerie,catering',
        ]);

        $this->get('/timisoara/restaurant-pizzerie-catering-al-pacino-36.html')
            ->assertOk()
            ->assertSee('<title>Restaurant,pizzerie,catering Al Pacino, Timisoara - AziLaPranz.ro</title>', false);
    }

    /**
     * `url` is the venue's own website. An accessor named getUrlAttribute()
     * would silently shadow the column and render the page's own address here.
     */
    public function test_the_url_column_holds_the_venues_own_website(): void
    {
        $this->venue(['url' => 'http://alduomo.ro']);

        $this->assertSame('http://alduomo.ro', Location::find(560)->url);

        $this->get('/timisoara/restaurant-al-duomo-560.html')
            ->assertOk()
            ->assertSee('http://alduomo.ro');
    }

    public function test_a_wrong_slug_redirects_to_the_canonical_url(): void
    {
        $this->venue();

        $this->get('/timisoara/anything-at-all-560.html')
            ->assertStatus(301)
            ->assertRedirect('/timisoara/restaurant-al-duomo-560.html');
    }

    public function test_a_wrong_city_redirects_to_the_canonical_url(): void
    {
        $this->venue();

        $this->get('/arad/restaurant-al-duomo-560.html')
            ->assertStatus(301)
            ->assertRedirect('/timisoara/restaurant-al-duomo-560.html');
    }

    public function test_a_suspended_venue_is_gone_rather_than_a_soft_404(): void
    {
        $this->venue(['status' => 'suspended']);

        $this->get('/timisoara/restaurant-al-duomo-560.html')->assertStatus(410);
    }

    public function test_an_unknown_venue_id_is_a_real_404(): void
    {
        $this->get('/timisoara/restaurant-nobody-999999.html')->assertNotFound();
    }

    public function test_a_slug_without_a_trailing_id_is_a_404(): void
    {
        $this->get('/timisoara/no-trailing-id.html')->assertNotFound();
    }

    public function test_an_unrouted_city_is_a_404(): void
    {
        $this->get('/ploiesti/restaurante.html')->assertNotFound();
    }

    public function test_junk_urls_are_404_not_the_homepage(): void
    {
        $this->get('/complete-junk')->assertNotFound();
        $this->get('/some/deep/junk/path')->assertNotFound();
    }

    /** @return array<string, array{string, string}> */
    public static function redirects(): array
    {
        return [
            'bare city' => ['/timisoara', '/timisoara/restaurante.html'],
            'daily menu' => ['/timisoara/meniul-zilei.html', '/timisoara/restaurante.html'],
            'dated daily menu' => ['/timisoara/meniul-zilei-10-august-2026.html', '/timisoara/restaurante.html'],
            'single-segment daily menu' => ['/meniul-zilei-timisoara', '/timisoara/restaurante.html'],
            'special offers' => ['/timisoara/oferte-speciale.html', '/timisoara/restaurante.html'],
            'dated special offers' => ['/arad/oferte-speciale-1-mai-2013.html', '/arad/restaurante.html'],
            'single-segment offers' => ['/oferte-speciale-arad', '/arad/restaurante.html'],
            'valentines 2014' => ['/timisoara/valentines-day-2014/foo-12.html', '/timisoara/restaurante.html'],
            'revelion 2012' => ['/revelion-timisoara-2012/foo-12.html', '/timisoara/restaurante.html'],
            'extensionless page' => ['/contact', '/contact.html'],
            'bare restaurante' => ['/restaurante.html', '/timisoara/restaurante.html'],
        ];
    }

    #[DataProvider('redirects')]
    public function test_legacy_urls_redirect_permanently(string $from, string $to): void
    {
        $this->get($from)
            ->assertStatus(301)
            ->assertRedirect($to);
    }

    public function test_city_listing_shows_its_venues(): void
    {
        $this->venue();

        $this->get('/timisoara/restaurante.html')
            ->assertOk()
            ->assertSee('<title>Lista restaurantelor din Timisoara - AziLaPranz.ro</title>', false)
            ->assertSee('Al Duomo')
            ->assertSee('restaurant-al-duomo-560.html', false);
    }

    /**
     * The legacy matched venues on the URL slug ("Cluj-napoca") rather than the
     * stored city name ("Cluj Napoca"), leaving this listing empty in production.
     */
    public function test_the_cluj_napoca_listing_is_not_empty(): void
    {
        $this->venue([
            'id' => 299,
            'name' => 'Hermes',
            'slug' => 'restaurant-hermes-299',
            'city' => 'Cluj Napoca',
            'city_slug' => 'cluj-napoca',
        ]);

        $this->get('/cluj-napoca/restaurante.html')
            ->assertOk()
            ->assertSee('Hermes')
            ->assertSee('<title>Lista restaurantelor din Cluj Napoca - AziLaPranz.ro</title>', false);
    }

    /**
     * Laravel 13 has a @context Blade directive, and inline template text is
     * directive-compiled before echoes are — so a literal '@context' key
     * written inside {!! json_encode([...]) !!} is replaced with PHP source.
     * The result is still valid JSON, and Google silently discards a JSON-LD
     * block that has no @context, so nothing else catches this.
     *
     * @param  string  $url  a page that emits JSON-LD
     */
    #[DataProvider('jsonLdPages')]
    public function test_json_ld_keeps_its_context_key(string $url): void
    {
        $this->venue();

        $html = $this->get($url)->assertOk()->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $this->assertNotEmpty($m[1], "No JSON-LD found on {$url}");

        foreach ($m[1] as $block) {
            $decoded = json_decode($block, true);

            $this->assertIsArray($decoded, "JSON-LD on {$url} did not parse");
            $this->assertArrayHasKey('@context', $decoded, "JSON-LD on {$url} lost its @context key");
            $this->assertSame('https://schema.org', $decoded['@context']);
        }
    }

    public static function jsonLdPages(): array
    {
        return [
            'city listing' => ['/timisoara/restaurante.html'],
            'venue page' => ['/timisoara/restaurant-al-duomo-560.html'],
        ];
    }

    /**
     * Every non-suspended venue returns 200, but only 'active' ones are linked
     * from the listings. Leaving the rest out of the sitemap would make them
     * orphans — indexable, unlinked, unannounced.
     */
    public function test_the_sitemap_covers_venues_that_are_not_in_the_listings(): void
    {
        $this->venue(['id' => 700, 'name' => 'Standby', 'slug' => 'restaurant-standby-700', 'status' => 'nooffer']);

        $this->get('/timisoara/restaurante.html')
            ->assertOk()
            ->assertDontSee('restaurant-standby-700.html', false);

        $this->get('/timisoara/restaurant-standby-700.html')->assertOk();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/timisoara/restaurant-standby-700.html'));
    }

    public function test_the_sitemap_lists_venues_and_city_pages(): void
    {
        $this->venue();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=utf-8')
            ->assertSee(url('/timisoara/restaurante.html'))
            ->assertSee(url('/timisoara/restaurant-al-duomo-560.html'));
    }

    /**
     * Both files are live on the legacy site and are how the Search Console and
     * Bitly properties are verified. Losing them at cutover would unverify them.
     */
    public function test_the_site_verification_files_are_still_served(): void
    {
        $this->assertFileExists(public_path('google619c7d2c3f8ef1df.html'));
        $this->assertFileExists(public_path('eb067c8d04cd.html'));

        $this->assertStringContainsString(
            'google-site-verification: google619c7d2c3f8ef1df.html',
            file_get_contents(public_path('google619c7d2c3f8ef1df.html'))
        );
    }

    public function test_a_real_favicon_is_present(): void
    {
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_robots_points_at_the_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: '.url('/sitemap.xml'))
            ->assertSee('Disallow: /admin/');
    }

    public function test_the_rss_feed_still_serves(): void
    {
        $this->venue();

        $this->get('/rss.php')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=utf-8')
            ->assertSee(url('/timisoara/restaurant-al-duomo-560.html'));

        $this->get('/rss-locations.php')->assertOk();
    }

    public function test_legacy_thumbnail_urls_redirect_to_stored_assets(): void
    {
        // Only paths under userfiles/locations/ were migrated.
        $this->get('/thumb.php?image=/userfiles/offers/whatever.jpg&width=150&height=150')
            ->assertNotFound();

        $this->get('/thumbs/150/150/userfiles/offers/whatever.jpg')
            ->assertNotFound();
    }
}

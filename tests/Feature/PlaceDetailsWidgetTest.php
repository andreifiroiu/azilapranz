<?php

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Places UI Kit widget on a venue page.
 *
 * The widget is the only place Google's venue content is allowed to appear:
 * it renders client-side under the EEA §15.3 exemption and stores nothing. So
 * what matters here is that it appears only when it should, and that the page
 * degrades to the legacy presentation when it does not.
 */
class PlaceDetailsWidgetTest extends TestCase
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
            'address' => 'Str Paul Chinezu, nr 2',
            'status' => 'active',
            'rating' => 18,
            'rating_votes' => 4,
        ], $overrides));
    }

    private function enableUiKit(): void
    {
        config()->set('azp.google.ui_kit', true);
        config()->set('azp.google.maps_browser_key', 'browser-key');
    }

    public function test_the_widget_renders_for_a_venue_with_a_place_id(): void
    {
        $this->enableUiKit();
        $venue = $this->venue(['place_id' => 'ChIJ_place_id']);

        // The loader assembles its host at runtime (`maps.${c}apis.com`), so
        // the importLibrary call is what marks its presence in the HTML.
        $this->get($venue->path)
            ->assertOk()
            ->assertSee('<gmp-place-details-place-request place-id="ChIJ_place_id">', false)
            ->assertSee("importLibrary('places')", false)
            ->assertSee('"browser-key"', false);
    }

    public function test_a_venue_without_a_place_id_degrades_cleanly(): void
    {
        $this->enableUiKit();
        $venue = $this->venue();

        $this->get($venue->path)
            ->assertOk()
            ->assertDontSee('gmp-place-details', false)
            ->assertDontSee("importLibrary('places')", false);
    }

    public function test_nothing_loads_while_the_ui_kit_is_switched_off(): void
    {
        config()->set('azp.google.ui_kit', false);
        $venue = $this->venue(['place_id' => 'ChIJ_place_id']);

        $this->get($venue->path)
            ->assertOk()
            ->assertDontSee('gmp-place-details', false);
    }

    public function test_the_legacy_rating_gives_way_to_googles(): void
    {
        $this->enableUiKit();
        $venue = $this->venue(['place_id' => 'ChIJ_place_id']);

        // 18 points across 4 votes — the legacy average this page used to show.
        $this->get($venue->path)
            ->assertOk()
            ->assertDontSee('>4.5</span>', false)
            ->assertDontSee('AggregateRating', false);
    }

    public function test_the_legacy_rating_survives_where_the_widget_is_absent(): void
    {
        $venue = $this->venue();

        $this->get($venue->path)
            ->assertOk()
            ->assertSee('>4.5</span>', false)
            ->assertSee('voturi')
            ->assertSee('AggregateRating', false);
    }
}

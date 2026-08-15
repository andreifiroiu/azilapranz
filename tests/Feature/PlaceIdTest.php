<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The place ID pipeline, and the licence boundary it exists to respect.
 *
 * We may store a place ID indefinitely and nothing else from a Places
 * response. Several assertions here exist purely to hold that line — if one of
 * them fails because a field mask widened, the fix is to narrow the mask, not
 * to update the test.
 */
class PlaceIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('azp.google.places_key', 'test-key');
    }

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
            'phone' => '0256.437.199',
            'status' => 'active',
        ], $overrides));
    }

    public function test_backfill_stores_the_place_id_and_nothing_else(): void
    {
        $venue = $this->venue();

        // A response deliberately fatter than the field mask asked for: if the
        // mask is ever widened, this data is what would leak into the table.
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'places' => [[
                    'id' => 'ChIJ_place_id',
                    'displayName' => ['text' => 'Al Duomo Ristorante'],
                    'formattedAddress' => 'Strada Paul Chinezu 2, Timisoara',
                    'nationalPhoneNumber' => '0256 437 199',
                    'rating' => 4.6,
                ]],
            ]),
        ]);

        $this->artisan('azp:places:backfill')->assertSuccessful();

        $venue->refresh();

        $this->assertSame('ChIJ_place_id', $venue->place_id);
        $this->assertSame(GooglePlaces::OK, $venue->place_id_status);
        $this->assertNotNull($venue->place_id_checked_at);

        // The venue's own data is untouched by the Google response.
        $this->assertSame('Al Duomo', $venue->name);
        $this->assertSame('Str Paul Chinezu, nr 2', $venue->address);
        $this->assertSame('0256.437.199', $venue->phone);
        $this->assertSame('0.00', (string) $venue->rating);
    }

    public function test_backfill_requests_only_the_id_field(): void
    {
        $this->venue();

        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'x']]])]);

        $this->artisan('azp:places:backfill')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->header('X-Goog-FieldMask') === ['places.id']);
    }

    public function test_a_venue_google_cannot_match_is_recorded_as_unmatched(): void
    {
        $venue = $this->venue();

        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []])]);

        $this->artisan('azp:places:backfill')->assertSuccessful();

        $venue->refresh();

        $this->assertNull($venue->place_id);
        $this->assertSame(GooglePlaces::UNMATCHED, $venue->place_id_status);
    }

    public function test_a_vanished_place_is_flagged_but_never_auto_suspended(): void
    {
        $venue = $this->venue(['place_id' => 'ChIJ_gone', 'place_id_status' => GooglePlaces::OK]);

        Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

        $this->artisan('azp:places:refresh')
            ->expectsOutputToContain('need a human look')
            ->assertSuccessful();

        $venue->refresh();

        $this->assertSame(GooglePlaces::NOT_FOUND, $venue->place_id_status);
        // `status` drives routing and the deliberate 410s. Only a human moves it.
        $this->assertSame('active', $venue->status);
    }

    public function test_a_place_that_moved_adopts_the_replacement_id(): void
    {
        $venue = $this->venue(['place_id' => 'ChIJ_old', 'place_id_status' => GooglePlaces::OK]);

        Http::fake(['places.googleapis.com/*' => Http::response(['id' => 'ChIJ_new'])]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        $this->assertSame('ChIJ_new', $venue->refresh()->place_id);
    }

    public function test_a_rate_limit_leaves_the_venue_untouched_for_the_next_run(): void
    {
        // Whole seconds: the column has no sub-second precision to compare on.
        $checkedAt = now()->subYear()->startOfSecond();
        $venue = $this->venue([
            'place_id' => 'ChIJ_fine',
            'place_id_status' => GooglePlaces::OK,
            'place_id_checked_at' => $checkedAt,
        ]);

        Http::fake(['places.googleapis.com/*' => Http::response([], 429)]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        $venue->refresh();

        $this->assertSame('ChIJ_fine', $venue->place_id);
        $this->assertSame(GooglePlaces::OK, $venue->place_id_status);
        // The crucial part: not stamped as checked, so it is retried rather
        // than skipped for another month.
        $this->assertSame(
            $checkedAt->format('Y-m-d H:i:s'),
            $venue->place_id_checked_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_failed_search_does_not_mark_a_venue_unmatched(): void
    {
        $venue = $this->venue();

        Http::fake(['places.googleapis.com/*' => Http::response([], 500)]);

        $this->artisan('azp:places:backfill')
            ->expectsOutputToContain('deferred')
            ->assertSuccessful();

        $venue->refresh();

        $this->assertNull($venue->place_id_status);
        $this->assertNull($venue->place_id_checked_at);
    }

    public function test_refresh_skips_ids_checked_recently(): void
    {
        $this->venue(['place_id' => 'ChIJ_recent', 'place_id_checked_at' => now()->subDay()]);

        Http::fake();

        $this->artisan('azp:places:refresh --older-than=30')
            ->expectsOutputToContain('No place IDs are due')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_commands_stop_when_no_api_key_is_configured(): void
    {
        config()->set('azp.google.places_key', null);
        $this->venue();

        $this->artisan('azp:places:backfill')->assertFailed();
        $this->artisan('azp:places:refresh')->assertFailed();
    }
}

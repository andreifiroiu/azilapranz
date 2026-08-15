<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * How the Places integration behaves when Google does not cooperate.
 *
 * The rule these all serve: a failure that says nothing about the venue must
 * never be written to the venue's record. A rate limit is not a closed
 * restaurant, and a timeout is not an unlisted one.
 */
class PlacesFailureTest extends TestCase
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
            'status' => 'active',
        ], $overrides));
    }

    /**
     * retry(throw: false) suppresses error *responses*, not connection
     * failures — those still throw. Uncaught, one timeout would abort a
     * 500-venue run and lose the summary for everything already done.
     */
    public function test_a_connection_failure_defers_instead_of_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $result = GooglePlaces::make()->search($this->venue());

        $this->assertSame(GooglePlaces::DEFERRED, $result['status']);
        $this->assertNull($result['place_id']);
    }

    public function test_a_connection_failure_during_refresh_defers(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $result = GooglePlaces::make()->refresh('ChIJ_abc');

        $this->assertSame(GooglePlaces::DEFERRED, $result['status']);
    }

    public function test_a_rate_limit_defers_rather_than_marking_the_venue_unmatched(): void
    {
        Http::fake(fn () => Http::response(['error' => 'RESOURCE_EXHAUSTED'], 429));

        $this->assertSame(GooglePlaces::DEFERRED, GooglePlaces::make()->search($this->venue())['status']);
    }

    /** A revoked key must not read as "none of these restaurants exist". */
    public function test_a_rejected_key_defers_rather_than_marking_the_venue_unmatched(): void
    {
        Http::fake(fn () => Http::response(['error' => 'PERMISSION_DENIED'], 403));

        $this->assertSame(GooglePlaces::DEFERRED, GooglePlaces::make()->search($this->venue())['status']);
    }

    /**
     * Every DEFERRED result carries a null place_id, so a caller that forgot to
     * skip the write cannot be handed a stale ID to persist.
     */
    public function test_a_deferred_refresh_never_carries_a_place_id(): void
    {
        Http::fake(fn () => Http::response(['error' => 'unavailable'], 503));

        $result = GooglePlaces::make()->refresh('ChIJ_abc');

        $this->assertTrue(GooglePlaces::isDeferred($result));
        $this->assertNull($result['place_id']);
    }

    public function test_a_deferred_backfill_leaves_the_record_untouched(): void
    {
        $venue = $this->venue();
        Http::fake(fn () => Http::response([], 429));

        $this->artisan('azp:places:backfill')->assertSuccessful();

        $venue->refresh();
        $this->assertNull($venue->place_id);
        $this->assertNull($venue->place_id_status);
        $this->assertNull($venue->place_id_checked_at);
    }

    public function test_a_deferred_refresh_leaves_the_checked_at_stamp_alone(): void
    {
        $venue = $this->venue(['place_id' => 'ChIJ_abc', 'place_id_status' => GooglePlaces::OK]);
        Http::fake(fn () => Http::response([], 503));

        $this->artisan('azp:places:refresh --older-than=0')->assertSuccessful();

        $venue->refresh();
        $this->assertSame('ChIJ_abc', $venue->place_id);
        $this->assertNull($venue->place_id_checked_at);
    }

    /**
     * A venue a previous search found nothing for stores a null place_id, so a
     * naive "where place_id is null" filter re-queries — and re-pays for — it
     * on every subsequent run.
     */
    public function test_backfill_skips_venues_already_found_unmatched(): void
    {
        $this->venue(['place_id_status' => GooglePlaces::UNMATCHED, 'place_id_checked_at' => now()]);

        Http::fake();

        $this->artisan('azp:places:backfill')
            ->expectsOutputToContain('Nothing to resolve.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_backfill_retries_unmatched_venues_on_request(): void
    {
        $this->venue(['place_id_status' => GooglePlaces::UNMATCHED, 'place_id_checked_at' => now()]);

        Http::fake(fn () => Http::response(['places' => [['id' => 'ChIJ_new']]], 200));

        $this->artisan('azp:places:backfill --retry-unmatched')->assertSuccessful();

        $this->assertSame('ChIJ_new', Location::find(560)->place_id);
    }

    /**
     * 552 real restaurants matching nothing is a broken integration, not a
     * fact about the venues.
     */
    public function test_an_all_unmatched_run_fails_rather_than_recording_a_verdict(): void
    {
        foreach (range(1, 25) as $i) {
            $this->venue([
                'id' => $i,
                'name' => 'Venue '.$i,
                'slug' => 'restaurant-venue-'.$i.'-'.$i,
            ]);
        }

        // HTTP 200 with a body that carries no places — a changed response
        // shape looks exactly like this.
        Http::fake(fn () => Http::response([], 200));

        $this->artisan('azp:places:backfill')->assertFailed();
    }

    public function test_a_normal_run_still_succeeds(): void
    {
        $this->venue();
        Http::fake(fn () => Http::response(['places' => [['id' => 'ChIJ_ok']]], 200));

        $this->artisan('azp:places:backfill')->assertSuccessful();

        $venue = Location::find(560);
        $this->assertSame('ChIJ_ok', $venue->place_id);
        $this->assertSame(GooglePlaces::OK, $venue->place_id_status);
    }

    /**
     * A cleared ID drops the venue out of refresh's own pool, so backfill has
     * to be able to pick it up again — and be scheduled, not typed by hand.
     */
    public function test_a_venue_whose_place_vanished_is_picked_up_again_by_backfill(): void
    {
        $venue = $this->venue(['place_id' => 'ChIJ_gone', 'place_id_status' => GooglePlaces::OK]);

        // Keyed by URL rather than re-faking between the two commands:
        // Http::fake() appends stubs, so a second call would not replace the
        // first and the details 404 would answer the search too.
        Http::fake([
            '*/places/ChIJ_gone' => Http::response(['error' => 'NOT_FOUND'], 404),
            '*places:searchText' => Http::response(['places' => [['id' => 'ChIJ_relocated']]], 200),
        ]);

        $this->artisan('azp:places:refresh --older-than=0')->assertSuccessful();

        $venue->refresh();
        $this->assertNull($venue->place_id);
        $this->assertSame(GooglePlaces::NOT_FOUND, $venue->place_id_status);

        // Not UNMATCHED, so the default backfill filter must still include it.
        $this->artisan('azp:places:backfill')->assertSuccessful();

        $this->assertSame('ChIJ_relocated', $venue->fresh()->place_id);
    }
}

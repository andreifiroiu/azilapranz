<?php

namespace Tests\Feature;

use App\Mail\VenueReviewReport;
use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        // Not OK: a search establishes identity, never trading status.
        $this->assertSame(GooglePlaces::RESOLVED, $venue->place_id_status);
        // Left unchecked on purpose, so the next refresh settles it.
        $this->assertNull($venue->place_id_checked_at);

        // The venue's own data is untouched by the Google response.
        $this->assertSame('Al Duomo', $venue->name);
        $this->assertSame('Str Paul Chinezu, nr 2', $venue->address);
        $this->assertSame('0256.437.199', $venue->phone);
        $this->assertSame('0.00', (string) $venue->rating);
    }

    public function test_a_closed_venue_can_still_be_re_resolved_and_reopened(): void
    {
        Mail::fake();

        // The state a venue lands in after refresh 404s and sync-status closes
        // it: no place_id, so refresh cannot see it. If backfill cannot either,
        // it is stranded closed forever.
        $venue = $this->venue([
            'status' => Location::STATUS_CLOSED,
            'place_id' => null,
            'place_id_status' => GooglePlaces::NOT_FOUND,
            'auto_closed_from' => 'active',
            'auto_closed_at' => now()->subMonth(),
        ]);

        // Distinct patterns per endpoint: a second Http::fake() for the same
        // pattern does not replace the first, it queues behind it, so both
        // stubs have to be registered up front to be reachable.
        Http::fake([
            'places.googleapis.com/v1/places:searchText' => Http::response([
                'places' => [['id' => 'ChIJ_back']],
            ]),
            'places.googleapis.com/v1/places/*' => Http::response([
                'id' => 'ChIJ_back', 'businessStatus' => 'OPERATIONAL',
            ]),
        ]);

        $this->artisan('azp:places:backfill')->assertSuccessful();

        $venue->refresh();
        $this->assertSame('ChIJ_back', $venue->place_id);

        // Crucially not OK — the search found the place, which says nothing
        // about whether it trades. Reopening on this alone would flip-flop:
        // Text Search returns permanently closed restaurants too.
        $this->assertSame(GooglePlaces::RESOLVED, $venue->place_id_status);

        $this->artisan('azp:places:sync-status --force')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();
        $this->assertSame(Location::STATUS_CLOSED, $venue->refresh()->status);

        // Only a refresh, which can see businessStatus, settles it.
        $this->artisan('azp:places:refresh')->assertSuccessful();
        $this->assertSame(GooglePlaces::OK, $venue->refresh()->place_id_status);

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        $venue->refresh();
        $this->assertSame('active', $venue->status);
        $this->assertNull($venue->auto_closed_from);
    }

    public function test_backfill_leaves_venues_a_human_suspended_alone(): void
    {
        $venue = $this->venue(['status' => Location::STATUS_SUSPENDED]);

        Http::fake(['places.googleapis.com/*' => Http::response(['places' => [['id' => 'x']]])]);

        $this->artisan('azp:places:backfill')
            ->expectsOutputToContain('Nothing to resolve')
            ->assertSuccessful();

        $this->assertNull($venue->refresh()->place_id);
        Http::assertNothingSent();
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

        // Artisan::call, because expectsOutputToContain does not see inside a
        // rendered table and would pass whatever the table actually printed.
        $this->assertSame(0, Artisan::call('azp:places:backfill'));
        $output = Artisan::output();

        // Named, not just counted: an unmatched venue needs a person to look at
        // its address, and a bare tally gives them nothing to go on.
        $this->assertStringContainsString('Al Duomo', $output);
        $this->assertStringContainsString('Str Paul Chinezu, nr 2', $output);

        $venue->refresh();

        $this->assertNull($venue->place_id);
        $this->assertSame(GooglePlaces::UNMATCHED, $venue->place_id_status);
    }

    public function test_a_dry_run_still_names_the_unmatched_venues(): void
    {
        $venue = $this->venue();

        Http::fake(['places.googleapis.com/*' => Http::response(['places' => []])]);

        $this->assertSame(0, Artisan::call('azp:places:backfill --dry-run'));

        $this->assertStringContainsString('Al Duomo', Artisan::output());

        // Naming them is the whole value of a dry run; writing is not.
        $this->assertNull($venue->refresh()->place_id_status);
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

    public function test_a_permanently_closed_business_is_flagged_and_emailed(): void
    {
        Mail::fake();
        config()->set('azp.alert_email', 'redactie@azilapranz.ro');

        $venue = $this->venue(['place_id' => 'ChIJ_shut', 'place_id_status' => GooglePlaces::OK]);

        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => 'ChIJ_shut',
            'businessStatus' => 'CLOSED_PERMANENTLY',
        ])]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        $venue->refresh();

        $this->assertSame(GooglePlaces::CLOSED_PERMANENTLY, $venue->place_id_status);
        // The place still exists, so the ID is worth keeping.
        $this->assertSame('ChIJ_shut', $venue->place_id);
        // Still a flag, not a verdict.
        $this->assertSame('active', $venue->status);

        Mail::assertSent(VenueReviewReport::class, fn ($mail) => $mail->hasTo('redactie@azilapranz.ro')
            && $mail->venues->contains(fn ($l) => $l->id === $venue->id));
    }

    public function test_a_temporary_closure_is_recorded_separately(): void
    {
        Mail::fake();
        $venue = $this->venue(['place_id' => 'ChIJ_pause', 'place_id_status' => GooglePlaces::OK]);

        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => 'ChIJ_pause',
            'businessStatus' => 'CLOSED_TEMPORARILY',
        ])]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        $this->assertSame(GooglePlaces::CLOSED_TEMPORARILY, $venue->refresh()->place_id_status);
    }

    public function test_a_missing_business_status_is_neither_a_closure_nor_a_confirmation(): void
    {
        Mail::fake();
        $venue = $this->venue(['place_id' => 'ChIJ_quiet', 'place_id_status' => GooglePlaces::OK]);

        // Google omits businessStatus for places it has no trading status for.
        Http::fake(['places.googleapis.com/*' => Http::response(['id' => 'ChIJ_quiet'])]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        // Not a closure, but not OK either: OK is what sync-status reopens a
        // venue on, and absence is not evidence that a business is trading.
        $this->assertSame(GooglePlaces::RESOLVED, $venue->refresh()->place_id_status);
        Mail::assertNothingSent();
    }

    public function test_an_unparseable_body_is_deferred_not_treated_as_trading(): void
    {
        Mail::fake();
        $venue = $this->venue([
            'place_id' => 'ChIJ_shut',
            'place_id_status' => GooglePlaces::CLOSED_PERMANENTLY,
            'place_id_checked_at' => now()->subYear(),
        ]);

        // A proxy interstitial or a reshaped response: HTTP 200, not JSON.
        Http::fake(['places.googleapis.com/*' => Http::response('<html>nope</html>', 200)]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        // Without the guard this read as "trading" and would reopen every
        // closed venue in the directory in one run.
        $this->assertSame(GooglePlaces::CLOSED_PERMANENTLY, $venue->refresh()->place_id_status);
    }

    public function test_a_rejected_field_mask_does_not_mark_every_venue_invalid(): void
    {
        Mail::fake();
        $venue = $this->venue(['place_id' => 'ChIJ_fine', 'place_id_status' => GooglePlaces::OK]);

        // A real field-mask rejection. Note it names `places.v1.Place`, which
        // is why the classification cannot be based on the error text.
        Http::fake(['places.googleapis.com/*' => Http::response([
            'error' => [
                'status' => 'INVALID_ARGUMENT',
                'message' => "Cannot find matching fields for path 'businessStatus' in message google.maps.places.v1.Place.",
            ],
        ], 400)]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        $venue->refresh();

        // Blaming the venue would have wiped every stored place ID overnight.
        $this->assertSame('ChIJ_fine', $venue->place_id);
        $this->assertSame(GooglePlaces::OK, $venue->place_id_status);
    }

    public function test_a_venue_already_flagged_is_not_emailed_again(): void
    {
        Mail::fake();
        config()->set('azp.alert_email', 'redactie@azilapranz.ro');

        $this->venue([
            'place_id' => 'ChIJ_shut',
            'place_id_status' => GooglePlaces::CLOSED_PERMANENTLY,
            'place_id_checked_at' => now()->subYear(),
            'place_id_status_changed_at' => now()->subYear(),
            // Already reported successfully.
            'place_id_flag_reported_at' => now()->subMonth(),
        ]);

        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => 'ChIJ_shut',
            'businessStatus' => 'CLOSED_PERMANENTLY',
        ])]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        // Unchanged and already reported — otherwise the alert repeats until
        // someone acts, and stops being read.
        Mail::assertNothingSent();
    }

    public function test_the_refresh_asks_for_the_id_and_business_status_only(): void
    {
        Mail::fake();
        $this->venue(['place_id' => 'ChIJ_x']);

        Http::fake(['places.googleapis.com/*' => Http::response(['id' => 'ChIJ_x'])]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->header('X-Goog-FieldMask') === ['id,businessStatus']);
    }

    public function test_findings_survive_a_broken_mailer(): void
    {
        config()->set('azp.alert_email', 'redactie@azilapranz.ro');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $venue = $this->venue(['place_id' => 'ChIJ_shut', 'place_id_status' => GooglePlaces::OK]);

        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => 'ChIJ_shut',
            'businessStatus' => 'CLOSED_PERMANENTLY',
        ])]);

        // The billed calls already happened; a dead mailer must not throw them away.
        $this->artisan('azp:places:refresh')->assertSuccessful();

        $this->assertSame(GooglePlaces::CLOSED_PERMANENTLY, $venue->refresh()->place_id_status);
    }

    public function test_a_malformed_stored_id_is_rejected_without_a_billed_call(): void
    {
        Mail::fake();
        $venue = $this->venue(['place_id' => 'not a place id', 'place_id_status' => GooglePlaces::OK]);

        Http::fake();

        $this->artisan('azp:places:refresh')->assertSuccessful();

        // Decided locally: the answer is certain and an API round trip to be
        // told so would cost money and muddy the 400 handling above.
        $this->assertSame(GooglePlaces::INVALID, $venue->refresh()->place_id_status);
        Http::assertNothingSent();
    }

    public function test_a_cleared_id_is_kept_as_previous_place_id(): void
    {
        Mail::fake();
        $venue = $this->venue(['place_id' => 'ChIJ_gone', 'place_id_status' => GooglePlaces::OK]);

        Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        $venue->refresh();

        // An ID costs a billed search to regenerate and a re-search can match a
        // different venue, so the outgoing value is worth one nullable column.
        $this->assertNull($venue->place_id);
        $this->assertSame('ChIJ_gone', $venue->previous_place_id);
    }

    public function test_a_failed_send_leaves_the_venue_to_be_reported_again(): void
    {
        config()->set('azp.alert_email', 'redactie@azilapranz.ro');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $venue = $this->venue(['place_id' => 'ChIJ_shut', 'place_id_status' => GooglePlaces::OK]);

        Http::fake(['places.googleapis.com/*' => Http::response([
            'id' => 'ChIJ_shut', 'businessStatus' => 'CLOSED_PERMANENTLY',
        ])]);

        $this->artisan('azp:places:refresh')->assertSuccessful();

        $venue->refresh();
        $this->assertSame(GooglePlaces::CLOSED_PERMANENTLY, $venue->place_id_status);
        // Unreported, so tomorrow's run raises it again instead of losing it —
        // and sync-status refuses to act on it in the meantime.
        $this->assertNull($venue->place_id_flag_reported_at);

        $this->artisan('azp:places:sync-status --force')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();
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

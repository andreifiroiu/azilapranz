<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The only command that writes the `status` column.
 *
 * A closed venue leaves every listing but keeps its page, so the tests come in
 * two halves: what the command refuses to do (act on a fresh flag, act on an
 * ambiguous one, reverse a human's decision), and what `closed` means once it
 * is set — gone from the listings, still 200 at its URL, saying so on the page.
 */
class SyncVenueStatusTest extends TestCase
{
    use RefreshDatabase;

    private int $nextId = 1;

    private function venue(array $overrides = []): Location
    {
        $id = $this->nextId++;

        return Location::create(array_merge([
            'id' => $id,
            'name' => 'Al Duomo '.$id,
            'slug' => 'restaurant-al-duomo-'.$id,
            'city' => 'Timisoara',
            'city_slug' => 'timisoara',
            'type' => 'restaurant',
            'status' => 'active',
            'place_id' => 'ChIJ_'.$id,
            'place_id_status' => GooglePlaces::CLOSED_PERMANENTLY,
            'place_id_status_changed_at' => now()->subDays(30),
            'place_id_checked_at' => now(),
            'place_id_flag_reported_at' => now()->subDays(30),
            // A real rating, so the aggregateRating assertions exercise the
            // isClosed() clause instead of short-circuiting on a null average.
            'rating' => 9,
            'rating_votes' => 2,
        ], $overrides));
    }

    public function test_a_settled_permanent_closure_is_marked_closed(): void
    {
        $venue = $this->venue();

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        $venue->refresh();

        $this->assertSame(Location::STATUS_CLOSED, $venue->status);
        $this->assertSame('active', $venue->auto_closed_from);
        // Cast to a date, not left as a raw string — a stale cast for a renamed
        // column passes a null check but breaks the moment anyone formats it.
        $this->assertInstanceOf(Carbon::class, $venue->auto_closed_at);
    }

    public function test_a_closed_venue_keeps_its_page_and_announces_the_closure(): void
    {
        $venue = $this->venue();

        // Proves the isClosed() clause does the work: the same fixture while
        // open carries an AggregateRating block. Without this the assertion
        // below passed with the isClosed() clause deleted.
        $this->get($venue->path)->assertOk()->assertSee('AggregateRating', false);

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        $this->get($venue->path)
            ->assertOk()
            ->assertSee('Local închis definitiv')
            // Star snippets would be an invitation to visit.
            ->assertDontSee('AggregateRating', false);
    }

    public function test_a_closed_venue_does_not_render_googles_live_widget(): void
    {
        config()->set('azp.google.ui_kit', true);
        config()->set('azp.google.maps_browser_key', 'browser-key');

        $venue = $this->venue();

        $this->get($venue->path)->assertOk()->assertSee('gmp-place-details', false);

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        // Google's live hours and photos directly under our closure notice
        // would contradict the page; worse if a re-search matched a neighbour.
        $this->get($venue->path)
            ->assertOk()
            ->assertSee('Local închis definitiv')
            ->assertDontSee('gmp-place-details', false);
    }

    public function test_a_venue_a_human_reopened_is_not_closed_again(): void
    {
        $venue = $this->venue();

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();
        $this->assertSame(Location::STATUS_CLOSED, $venue->refresh()->status);

        // An editor checks it, finds it trading, sets it back by hand. Google
        // still says closed, so without a marker this is re-closed nightly.
        $venue->forceFill(['status' => 'active'])->save();

        $this->artisan('azp:places:sync-status --force')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_unverified_statuses_are_not_closable(): void
    {
        // standby/uncontacted venues were never verified by anyone. Backfill
        // now reaches them, but a match against a 2011 address is not grounds
        // for asserting a closure on their page.
        foreach (['standby', 'uncontacted'] as $status) {
            $venue = $this->venue(['status' => $status]);

            $this->artisan('azp:places:sync-status --force')->assertSuccessful();

            $this->assertSame($status, $venue->refresh()->status);
        }
    }

    public function test_a_flag_nobody_could_re_confirm_is_not_acted_on(): void
    {
        // DEFERRED freezes place_id_checked_at, so an outage used to make an
        // old flag *more* eligible with every passing day.
        $venue = $this->venue(['place_id_checked_at' => now()->subDays(60)]);

        $this->artisan('azp:places:sync-status --force --min-age=14')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_an_unreported_closure_is_not_acted_on(): void
    {
        $venue = $this->venue(['place_id_flag_reported_at' => null]);

        $this->artisan('azp:places:sync-status --force')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_a_mass_closure_is_refused(): void
    {
        foreach (range(1, 6) as $i) {
            $this->venue();
        }

        $this->artisan('azp:places:sync-status --force --max=5')
            ->expectsOutputToContain('over the --max')
            ->assertFailed();

        $this->assertSame(0, Location::where('status', Location::STATUS_CLOSED)->count());
    }

    public function test_the_ceiling_blocks_closures_without_blocking_reopenings(): void
    {
        // Reopening is the corrective direction. Blocking it because an
        // unrelated mass closure tripped the ceiling would strand a trading
        // venue out of every listing for as long as the problem went unnoticed.
        $reopening = $this->venue();
        $this->artisan('azp:places:sync-status --force')->assertSuccessful();
        $reopening->forceFill(['place_id_status' => GooglePlaces::OK])->save();

        foreach (range(1, 6) as $i) {
            $this->venue();
        }

        $this->artisan('azp:places:sync-status --force --max=5')->assertFailed();

        $this->assertSame('active', $reopening->refresh()->status);
        // The reopened one plus the six the ceiling refused to close.
        $this->assertSame(7, Location::where('status', Location::STATUS_ACTIVE)->count());
        $this->assertSame(0, Location::where('status', Location::STATUS_CLOSED)->count());
    }

    public function test_a_dry_run_over_the_ceiling_still_names_the_venues(): void
    {
        // The refusal message tells the operator to inspect the list; if the
        // ceiling short-circuited before the report, that advice was useless.
        foreach (range(1, 6) as $i) {
            $this->venue();
        }

        $this->assertSame(1, Artisan::call('azp:places:sync-status --dry-run --max=5'));

        $this->assertStringContainsString('Al Duomo 1', Artisan::output());
    }

    public function test_a_nonsense_max_is_rejected(): void
    {
        $venue = $this->venue();

        $this->artisan('azp:places:sync-status --force --max=abc')->assertFailed();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_a_venue_that_can_never_reopen_is_surfaced(): void
    {
        $venue = $this->venue();
        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        // Google resolves the place but reports no trading status, so `ok` is
        // unreachable and no code path can reopen this venue.
        $venue->forceFill(['place_id_status' => GooglePlaces::RESOLVED])->save();

        $this->assertSame(0, Artisan::call('azp:places:sync-status --force'));

        $this->assertStringContainsString('can no longer reopen automatically', Artisan::output());
    }

    public function test_a_nonsense_min_age_is_rejected(): void
    {
        $venue = $this->venue();

        $this->artisan('azp:places:sync-status --force --min-age=fourteen')->assertFailed();
        $this->artisan('azp:places:sync-status --force --min-age=-5')->assertFailed();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_a_closed_venue_leaves_the_listings(): void
    {
        $venue = $this->venue();
        $open = $this->venue(['place_id_status' => GooglePlaces::OK]);

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        $this->get('/timisoara/restaurante.html')
            ->assertOk()
            ->assertDontSee($venue->name)
            ->assertSee($open->name);
    }

    public function test_a_closed_venue_stays_in_the_sitemap(): void
    {
        // published(), not active(): a 200 page with no listing link and no
        // sitemap entry is exactly the orphan Google punishes as a soft 404.
        $venue = $this->venue();

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url($venue->path), false);
    }

    public function test_a_venue_closed_for_going_missing_says_so_differently(): void
    {
        $venue = $this->venue(['place_id_status' => GooglePlaces::NOT_FOUND]);

        $this->artisan('azp:places:sync-status --force --include-not-found')->assertSuccessful();

        $this->get($venue->path)
            ->assertOk()
            ->assertSee('Local închis sau relocat');
    }

    public function test_a_fresh_flag_is_left_alone(): void
    {
        $venue = $this->venue(['place_id_status_changed_at' => now()->subDays(3)]);

        $this->artisan('azp:places:sync-status --force --min-age=14')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_a_vanished_place_is_not_closed_by_default(): void
    {
        $venue = $this->venue(['place_id_status' => GooglePlaces::NOT_FOUND]);

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        // NOT_FOUND also fires on relocations and Google database churn.
        $this->assertSame('active', $venue->refresh()->status);

        $this->artisan('azp:places:sync-status --force --include-not-found')->assertSuccessful();

        $this->assertSame(Location::STATUS_CLOSED, $venue->refresh()->status);
    }

    public function test_a_venue_is_restored_to_the_status_it_actually_had(): void
    {
        // Not every venue is `active`; restoring to a constant would promote it.
        $venue = $this->venue(['status' => 'nooffer']);

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();
        $this->assertSame(Location::STATUS_CLOSED, $venue->refresh()->status);

        $venue->forceFill(['place_id_status' => GooglePlaces::OK])->save();

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        $venue->refresh();

        $this->assertSame('nooffer', $venue->status);
        $this->assertNull($venue->auto_closed_from);
        $this->assertNull($venue->auto_closed_at);
    }

    public function test_a_human_suspension_is_never_reversed(): void
    {
        // Suspended by a person: no auto_closed_from, and trading per Google.
        $venue = $this->venue([
            'status' => Location::STATUS_SUSPENDED,
            'place_id_status' => GooglePlaces::OK,
        ]);

        $this->artisan('azp:places:sync-status --force')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();

        $this->assertSame(Location::STATUS_SUSPENDED, $venue->refresh()->status);
    }

    public function test_a_human_suspension_is_not_downgraded_to_closed(): void
    {
        // Closed per Google, but a person already chose the stronger 410.
        // Overwriting that would turn a deliberate 410 back into a 200.
        $venue = $this->venue(['status' => Location::STATUS_SUSPENDED]);

        $this->artisan('azp:places:sync-status --force')
            ->expectsOutputToContain('Nothing to change')
            ->assertSuccessful();

        $this->assertSame(Location::STATUS_SUSPENDED, $venue->refresh()->status);
        $this->get($venue->path)->assertStatus(410);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $venue = $this->venue();

        $this->artisan('azp:places:sync-status --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_declining_the_prompt_writes_nothing(): void
    {
        $venue = $this->venue();

        $this->artisan('azp:places:sync-status')
            ->expectsConfirmation('Apply these changes?', 'no')
            ->assertSuccessful();

        $this->assertSame('active', $venue->refresh()->status);
    }

    public function test_a_venue_never_checked_against_google_is_ignored(): void
    {
        $venue = $this->venue([
            'place_id_status' => null,
            'place_id_status_changed_at' => null,
        ]);

        $this->artisan('azp:places:sync-status --force')->assertSuccessful();

        $this->assertSame('active', $venue->refresh()->status);
    }
}

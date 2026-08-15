<?php

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query budgets for the pages that render many venues.
 *
 * A city listing renders every active venue in the city — up to 186 in
 * Timisoara — so an accidental lazy-load in the row component turns one page
 * into hundreds of queries. These ceilings are deliberately loose; they exist
 * to catch an N+1 appearing, not to police exact counts.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function venues(int $count): void
    {
        foreach (range(1, $count) as $i) {
            Location::create([
                'id' => $i,
                'name' => 'Venue '.$i,
                'slug' => 'restaurant-venue-'.$i.'-'.$i,
                'city' => 'Timisoara',
                'city_slug' => 'timisoara',
                'type' => 'restaurant',
                'area' => 'Centru',
                'address' => 'Str. Test '.$i,
                'status' => 'active',
                'rating' => 8,
                'rating_votes' => 2,
            ]);
        }
    }

    /** @return int number of queries the request issued */
    private function countQueries(string $url): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_city_listing_does_not_scale_queries_with_venues(): void
    {
        $this->venues(60);

        $this->assertLessThan(
            10,
            $queries = $this->countQueries('/timisoara/restaurante.html'),
            "City listing issued {$queries} queries for 60 venues — likely an N+1."
        );
    }

    public function test_the_venue_page_stays_flat(): void
    {
        $this->venues(60);

        $this->assertLessThan(
            10,
            $queries = $this->countQueries('/timisoara/restaurant-venue-1-1.html'),
            "Venue page issued {$queries} queries — likely an N+1 in the related list."
        );
    }

    public function test_the_homepage_stays_flat(): void
    {
        $this->venues(60);

        $this->assertLessThan(10, $this->countQueries('/'));
    }

    public function test_the_sitemap_chunks_rather_than_loading_every_venue(): void
    {
        $this->venues(60);

        $this->assertLessThan(15, $this->countQueries('/sitemap.xml'));
    }
}

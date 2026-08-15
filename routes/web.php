<?php

use App\Http\Controllers\CityController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImageCompatController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SitemapController;
use App\Models\Location;
use App\Support\Cities;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| azilapranz.ro
|--------------------------------------------------------------------------
|
| Every route below either reproduces a URL the legacy PHP site had indexed or
| 301s it to its replacement. Ordering matters: the generic venue route
| /{city}/{slug}.html would otherwise swallow /{city}/meniul-zilei.html and
| /{city}/restaurante.html, so the specific patterns are registered first.
|
| Legacy URL inventory and the reasoning behind each redirect target live in
| ~/.claude/plans/analyze-the-old-website-snazzy-tulip.md.
|
*/

// Constrains {city} to the 7 slugs the legacy actually resolved. Anything else
// was a soft 404 on the old site and must stay a 404 here.
Route::pattern('city', Cities::routePattern());

Route::get('/', HomeController::class)->name('home');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots']);

// Legacy feeds — both have live subscribers per the rss_logs tables.
Route::get('/rss.php', [SitemapController::class, 'feed'])->name('feed');
Route::get('/rss-locations.php', [SitemapController::class, 'feed']);

/*
| City pages
*/

Route::get('/{city}/restaurante.html', [CityController::class, 'index'])->name('city');
Route::get('/{city}/harta-restaurante.html', [CityController::class, 'map'])->name('city.map');

// The legacy served /{city} and /{city}/restaurante.html as separate 200s with
// identical content and no canonical. Consolidate onto the listing URL.
Route::redirect('/{city}', '/{city}/restaurante.html', 301);

/*
| Dead daily-menu and special-offer URLs
|
| These rendered live offer data that no longer exists (one offer row in all of
| 2026), so they would now be empty pages. Redirect to the city listing, which
| is the closest surviving equivalent.
*/

Route::redirect('/{city}/meniul-zilei.html', '/{city}/restaurante.html', 301);
Route::redirect('/{city}/oferte-speciale.html', '/{city}/restaurante.html', 301);

Route::get('/{city}/meniul-zilei-{day}.html', fn (string $city) => redirect("/{$city}/restaurante.html", 301))
    ->where('day', '[a-z0-9\-]+');

Route::get('/{city}/oferte-speciale-{day}.html', fn (string $city) => redirect("/{$city}/restaurante.html", 301))
    ->where('day', '[a-z0-9\-]+');

// Single-segment variants: /meniul-zilei-timisoara, /oferte-speciale-arad
Route::get('/meniul-zilei-{city}', fn (string $city) => redirect("/{$city}/restaurante.html", 301));
Route::get('/oferte-speciale-{city}', fn (string $city) => redirect("/{$city}/restaurante.html", 301));

/*
| Retired seasonal campaigns
*/

Route::get('/{city}/valentines-day-2014/{rest?}', fn (string $city) => redirect("/{$city}/restaurante.html", 301))
    ->where('rest', '.*');

Route::get('/revelion-timisoara-2012/{rest?}', fn () => redirect('/timisoara/restaurante.html', 301))
    ->where('rest', '.*');

// The single legacy "partner" microsite, for venue id 1 (Cardinal Luxury).
Route::get('/cardinal-luxury/{rest?}', function () {
    $location = Location::find(1);

    return redirect($location?->path ?? '/timisoara/restaurante.html', 301);
})->where('rest', '.*');

/*
| Venue detail pages — the bulk of the indexed surface.
| Must be registered after every other /{city}/... pattern.
*/

Route::get('/{city}/{slug}.html', [LocationController::class, 'show'])
    ->where('slug', '[^/]+')
    ->name('location');

/*
| Static content pages
*/

$pages = implode('|', array_map('preg_quote', PageController::KNOWN));

Route::get('/{name}.html', [PageController::class, 'show'])->where('name', $pages)->name('page');
Route::get('/{name}', [PageController::class, 'redirectToHtml'])->where('name', $pages);

/*
| Legacy image URLs
*/

Route::get('/thumb.php', [ImageCompatController::class, 'thumbPhp']);
Route::get('/thumbs/{path}', [ImageCompatController::class, 'thumbs'])->where('path', '.*');

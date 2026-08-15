<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Cities;
use Symfony\Component\HttpFoundation\Response;

class HomeController extends Controller
{
    /**
     * "/" — the legacy served the Timisoara daily-menu page here, titled
     * "Meniul zilei Timisoara". The offers behind it are dead (one row in all of
     * 2026), so the page now leads with the Timisoara venue list, keeping the
     * same title and the same city framing.
     */
    public function __invoke(): Response
    {
        $citySlug = Cities::defaultSlug();

        $featured = Location::query()
            ->active()
            ->inCity($citySlug)
            ->orderByDesc('rating_votes')
            ->orderBy('name')
            ->limit(12)
            ->get();

        $counts = Location::query()
            ->active()
            ->whereIn('city_slug', Cities::slugs())
            ->selectRaw('city_slug, count(*) as total')
            ->groupBy('city_slug')
            ->pluck('total', 'city_slug')
            ->all();

        return response()->view('home', [
            'citySlug' => $citySlug,
            'cityName' => Cities::name($citySlug),
            'featured' => $featured,
            'counts' => $counts,
        ]);
    }
}

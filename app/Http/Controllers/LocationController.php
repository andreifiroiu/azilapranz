<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Cities;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocationController extends Controller
{
    /**
     * Venue detail page: /{city}/{slug}-{id}.html
     *
     * The legacy router resolved purely on the trailing id and accepted any
     * slug under any city, with no canonical tag — an unbounded duplicate-URL
     * space. We keep the id-based lookup so every historic URL still resolves,
     * but redirect anything non-canonical instead of serving it 200.
     */
    public function show(Request $request, string $city, string $slug): Response
    {
        if (! preg_match('/-(\d+)$/', $slug, $m)) {
            abort(404);
        }

        $location = Location::find((int) $m[1]);

        if (! $location) {
            abort(404);
        }

        // The legacy served these 200 with "pagina acestui restaurant nu mai
        // exista" in the body — a soft 404. 410 tells Google it is deliberate.
        if ($location->isSuspended()) {
            abort(410);
        }

        // Venues in cities the legacy never routed have no indexed URL to keep.
        if (! Cities::exists($location->city_slug)) {
            abort(404);
        }

        if ($city !== $location->city_slug || $slug !== $location->slug) {
            return redirect($location->path, 301);
        }

        $related = Location::query()
            ->active()
            ->inCity($location->city_slug)
            ->whereKeyNot($location->getKey())
            ->when($location->area, fn ($q) => $q->where('area', $location->area))
            ->orderBy('name')
            ->limit(8)
            ->get();

        if ($related->isEmpty()) {
            // Deterministic on purpose: a random fallback would hand Googlebot a
            // different internal link graph on every crawl and defeat caching.
            $related = Location::query()
                ->active()
                ->inCity($location->city_slug)
                ->whereKeyNot($location->getKey())
                ->orderBy('name')
                ->limit(8)
                ->get();
        }

        return response()->view('location', [
            'location' => $location,
            'citySlug' => $location->city_slug,
            'cityName' => Cities::name($location->city_slug),
            'related' => $related,
        ]);
    }
}

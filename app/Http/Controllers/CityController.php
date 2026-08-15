<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Cities;
use Symfony\Component\HttpFoundation\Response;

class CityController extends Controller
{
    /** Venue listing: /{city}/restaurante.html */
    public function index(string $city): Response
    {
        $locations = $this->listing($city);

        return response()->view('city', [
            'citySlug' => $city,
            'cityName' => Cities::name($city),
            'locations' => $locations,
            'grouped' => $this->groupByInitial($locations),
            'areas' => $this->areas($locations),
        ]);
    }

    /** Map view: /{city}/harta-restaurante.html */
    public function map(string $city): Response
    {
        $locations = $this->listing($city);

        return response()->view('map', [
            'citySlug' => $city,
            'cityName' => Cities::name($city),
            'locations' => $locations,
            'mappable' => $locations->filter->hasCoordinates()->values(),
        ]);
    }

    /**
     * The legacy matched venues on the URL slug rather than the stored city name
     * (index.php did ucwords("cluj-napoca") => "Cluj-napoca", which matches
     * nothing), so the Cluj-Napoca listing has been empty in production and its
     * 52 venues have no internal links. Matching on city_slug fixes that.
     */
    private function listing(string $city)
    {
        return Location::query()
            ->active()
            ->inCity($city)
            ->orderBy('name')
            ->get();
    }

    /**
     * A–Z index, as the legacy listing had.
     *
     * Names starting with a digit or a Romanian diacritic go to a "#" bucket:
     * the rail is built from range('A','Z'), so any other key would render a
     * section with no anchor pointing at it. (strtoupper is byte-based and
     * would leave "Ș" as its own phantom group.)
     */
    public const OTHER_BUCKET = '#';

    private function groupByInitial($locations): array
    {
        return $locations
            ->groupBy(function ($l) {
                $initial = mb_strtoupper(mb_substr($l->name, 0, 1));

                return preg_match('/^[A-Z]$/', $initial) ? $initial : self::OTHER_BUCKET;
            })
            ->sortKeys()
            ->all();
    }

    /** Distinct areas, splitting the comma-separated legacy column. */
    private function areas($locations): array
    {
        return $locations
            ->flatMap(fn ($l) => array_map('trim', explode(',', (string) $l->area)))
            ->reject(fn ($a) => $a === '')
            ->map(fn ($a) => ucwords(mb_strtolower($a)))
            ->countBy()
            ->sortKeys()
            ->all();
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Routable cities
    |--------------------------------------------------------------------------
    |
    | Keyed by URL slug => display name. This is exactly the set the legacy
    | index.php resolved: `select distinct city from locations where status in
    | ('active') and city not in (<excepted>)`. Cities outside this list were
    | soft 404s on the old site, so adding them would create new URLs rather
    | than preserve indexed ones.
    |
    | The slug is what appears in every indexed URL; the display name is what
    | appears in <title> and <h1>.
    |
    */

    'cities' => [
        'arad' => 'Arad',
        'bucuresti' => 'Bucuresti',
        'cluj-napoca' => 'Cluj Napoca',
        'constanta' => 'Constanta',
        'iasi' => 'Iasi',
        'oradea' => 'Oradea',
        'timisoara' => 'Timisoara',
    ],

    /*
    | Cities present in the data but excluded from routing by the legacy site
    | (constants in index.php). Their venues are imported but not published.
    */

    'excepted_cities' => [
        'Drobeta Turnu Severin',
        'Ploiesti',
        'Satu Mare',
        'Sibiu',
        'Targu Neamt',
        'Zalau',
    ],

    /*
    | The city the legacy site fell back to when none could be determined —
    | notably for "/" itself.
    */

    'default_city' => 'timisoara',

    /*
    | Romanian month names, used to recognise legacy dated menu URLs such as
    | /timisoara/meniul-zilei-10-august-2026.html (constants.inc.php:6).
    */

    'months' => [
        'ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie',
        'iulie', 'august', 'septembrie', 'octombrie', 'noiembrie', 'decembrie',
    ],

    /*
    | Carried over verbatim from the legacy layout.tpl — removing it would
    | invalidate the Search Console property.
    */

    'google_site_verification' => '5EmWE1AbiqabAB0HUN7KqdyJGHRtlH6fQj_-YyfpyUY',

    /*
    | Fallbacks appended to <title> / <meta description>, matching layout.tpl.
    */

    /*
    | Filesystem path to the legacy PHP site, used by azp:import-legacy to copy
    | venue logos out of userfiles/locations. Goes through config rather than
    | env() so it survives config:cache.
    */

    'legacy_site_path' => env('LEGACY_SITE_PATH'),

    'site_name' => 'AziLaPranz.ro',
    'meta_suffix' => 'Meniul zilei si alte oferte speciale de la restaurantele din orasul tau',
    'meta_keywords' => 'meniul zilei, oferte restaurante, catering, mancare, azi la pranz, ce mancam azi',

    /*
    |--------------------------------------------------------------------------
    | Google Places
    |--------------------------------------------------------------------------
    |
    | Two distinct uses, with very different licensing:
    |
    | `place_id` — the Places API is used ONLY to resolve and re-check a venue's
    | place ID. General Service Terms §3 lets us store that identifier
    | indefinitely; nothing else from the response may be persisted, because
    | EEA ToS §3.3.2(a)(iii) forbids saving business names, addresses or
    | reviews and §3.3.2(b) forbids caching the rest. A missing ID is our
    | signal that a venue has closed or moved.
    |
    | `ui_kit` — Places UI Kit renders live Google content (hours, photos,
    | reviews) client-side. EEA Service Specific Terms §15.3 exempts it from
    | both the Permitted Uses whitelist and the no-map rule that otherwise put
    | Places content off-limits to a directory like this one. It is billed per
    | element load, so it is opt-in.
    |
    */

    'google' => [
        'places_key' => env('GOOGLE_PLACES_API_KEY'),
        'maps_browser_key' => env('GOOGLE_MAPS_BROWSER_KEY'),
        'ui_kit' => env('GOOGLE_PLACES_UI_KIT', false),
    ],
];

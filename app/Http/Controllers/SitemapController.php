<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Cities;
use Symfony\Component\HttpFoundation\Response;

class SitemapController extends Controller
{
    /** The legacy site had no sitemap at all. */
    public function __invoke(): Response
    {
        $urls = [
            ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly'],
        ];

        foreach (Cities::slugs() as $slug) {
            $urls[] = [
                'loc' => url("/{$slug}/restaurante.html"),
                'priority' => '0.9',
                'changefreq' => 'weekly',
            ];
            $urls[] = [
                'loc' => url("/{$slug}/harta-restaurante.html"),
                'priority' => '0.6',
                'changefreq' => 'monthly',
            ];
        }

        // published(), not active(): every non-suspended venue in a routable
        // city returns 200, but only 'active' ones appear in the listings. The
        // rest would be orphans — indexable pages with no internal link and no
        // sitemap entry, which is what Google's soft-404 heuristics punish.
        Location::query()
            ->published()
            ->whereIn('city_slug', Cities::slugs())
            ->orderBy('city_slug')
            ->orderBy('name')
            ->each(function (Location $l) use (&$urls) {
                $urls[] = [
                    'loc' => url($l->path),
                    'priority' => '0.8',
                    'changefreq' => 'monthly',
                    'lastmod' => $l->updated_at?->toAtomString(),
                ];
            });

        foreach (PageController::KNOWN as $name) {
            if ($name === 'restaurante') {
                continue; // redirects
            }

            $urls[] = [
                'loc' => url("/{$name}.html"),
                'priority' => '0.4',
                'changefreq' => 'yearly',
            ];
        }

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    /** robots.txt is served dynamically so the Sitemap line tracks APP_URL. */
    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Disallow: /admin/',
            'Disallow: /util/',
            'Disallow: /log-viewer',
            'Allow: /',
            '',
            'Sitemap: '.url('/sitemap.xml'),
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /** Kept because the legacy feeds have live subscribers (rss_logs). */
    public function feed(?string $city = null): Response
    {
        $citySlug = $city && Cities::exists($city) ? $city : Cities::defaultSlug();

        $locations = Location::query()
            ->active()
            ->inCity($citySlug)
            ->orderByDesc('register_date')
            ->limit(50)
            ->get();

        return response()
            ->view('feed', [
                'cityName' => Cities::name($citySlug),
                'citySlug' => $citySlug,
                'locations' => $locations,
            ])
            ->header('Content-Type', 'application/rss+xml; charset=utf-8');
    }
}

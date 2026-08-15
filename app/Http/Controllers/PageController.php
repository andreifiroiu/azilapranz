<?php

namespace App\Http\Controllers;

use App\Models\Page;
use Symfony\Component\HttpFoundation\Response;

class PageController extends Controller
{
    /**
     * Static content pages the legacy served both as /{name} and /{name}.html.
     * The .html form is canonical (it is what the site's own nav linked), so the
     * bare form 301s to it.
     */
    public const KNOWN = [
        'contact',
        'despre-noi',
        'despre-comenzile-online',
        'servicii',
        'promovare',
        'concursuri',
        'app',
        'newsletter',
        'politica-de-confidentialitate',
        'termeni-si-conditii-facebook',
        'propune-locatie',
        'change-log',
        'restaurante',
    ];

    public function show(string $name): Response
    {
        if (! in_array($name, self::KNOWN, true)) {
            abort(404);
        }

        // /restaurante.html had no city segment; the legacy resolved it against
        // whatever city was in the visitor's cookie. Send it to the default city
        // rather than guessing from a cookie no crawler carries.
        if ($name === 'restaurante') {
            return redirect('/'.config('azp.default_city').'/restaurante.html', 301);
        }

        $page = Page::where('name', $name)->first();

        return response()->view('page', [
            'name' => $name,
            'page' => $page,
            'title' => $page?->title ?? $this->fallbackTitle($name),
            'metaDescription' => $page?->meta_description,
        ]);
    }

    public function redirectToHtml(string $name): Response
    {
        if (! in_array($name, self::KNOWN, true)) {
            abort(404);
        }

        // Go straight to the final target rather than hopping through
        // /restaurante.html, which redirects again.
        if ($name === 'restaurante') {
            return redirect('/'.config('azp.default_city').'/restaurante.html', 301);
        }

        return redirect('/'.$name.'.html', 301);
    }

    /** Titles for the pages that never had a `pages` row. */
    private function fallbackTitle(string $name): string
    {
        return match ($name) {
            'servicii' => 'Servicii',
            'promovare' => 'Variante de promovare',
            'concursuri' => 'Concursuri si castigatori',
            'app' => 'Aplicatii mobile',
            'newsletter' => 'Newsletter',
            'propune-locatie' => 'Propune locatie noua',
            'change-log' => 'Change log',
            default => ucfirst(str_replace('-', ' ', $name)),
        };
    }
}

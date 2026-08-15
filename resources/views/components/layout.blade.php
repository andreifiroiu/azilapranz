@props([
    'title' => null,
    'description' => null,
    'canonical' => null,
    'citySlug' => null,
    'image' => null,
    'robots' => null,
])

@php
    $siteName = config('azp.site_name');
    $metaSuffix = config('azp.meta_suffix');

    // Legacy layout.tpl composed the title as "{page} - AziLaPranz.ro" and
    // appended the boilerplate sentence to every meta description. Both are
    // reproduced so the indexed text does not shift.
    $fullTitle = $title ? $title.' - '.$siteName : $siteName;
    $fullDescription = trim(($description ? rtrim($description, '. ').'. ' : '').$metaSuffix);
    $canonicalUrl = $canonical ?? url()->current();
@endphp

<!DOCTYPE html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $fullTitle }}</title>
    <meta name="description" content="{{ $fullDescription }}">
    <meta name="keywords" content="{{ config('azp.meta_keywords') }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    @if ($robots)
        <meta name="robots" content="{{ $robots }}">
    @endif

    <meta name="google-site-verification" content="{{ config('azp.google_site_verification') }}">

    {{-- The legacy emitted one global set of OG tags on every page. These vary. --}}
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ $fullDescription }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="ro_RO">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
        <meta name="twitter:card" content="summary_large_image">
    @else
        <meta name="twitter:card" content="summary">
    @endif

    <link rel="icon" href="{{ url('/favicon.ico') }}" sizes="any">

    <link rel="alternate" type="application/rss+xml"
          title="{{ $siteName }} - Restaurante" href="{{ url('/rss.php') }}">

    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{ $head ?? '' }}
</head>
<body class="min-h-screen flex flex-col">

<a href="#continut"
   class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-3 focus:rounded focus:bg-ink focus:px-4 focus:py-2 focus:text-paper">
    Sari la conținut
</a>

<header class="sticky top-0 z-40 border-b border-rule bg-paper/95 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center gap-4 px-4 py-3 sm:px-6">
        <a href="{{ url('/') }}" class="group shrink-0">
            <span class="font-display text-xl font-bold tracking-tight text-ink sm:text-2xl">
                Azi<span class="text-mustard">La</span>Pranz
            </span>
        </a>

        <nav aria-label="Orașe" class="ml-auto hidden md:block">
            <ul class="flex flex-wrap items-center gap-x-1 text-sm">
                @foreach (\App\Support\Cities::all() as $slug => $name)
                    <li>
                        <a href="{{ url("/{$slug}/restaurante.html") }}"
                           @class([
                               'rounded px-2.5 py-1.5 transition-colors hover:bg-olive-soft',
                               'bg-ink text-paper hover:bg-ink' => $slug === $citySlug,
                               'text-muted' => $slug !== $citySlug,
                           ])
                           @if ($slug === $citySlug) aria-current="page" @endif>{{ $name }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>

        {{-- Native select keeps the mobile city switcher zero-JS-dependency. --}}
        <label class="ml-auto md:hidden">
            <span class="sr-only">Alege orașul</span>
            <select
                class="rounded border border-rule bg-card px-2 py-1.5 text-sm"
                onchange="if(this.value)window.location.href=this.value">
                @foreach (\App\Support\Cities::all() as $slug => $name)
                    <option value="{{ url("/{$slug}/restaurante.html") }}"
                            @selected($slug === $citySlug)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
    </div>
</header>

<main id="continut" class="flex-1">
    {{ $slot }}
</main>

<footer class="mt-16 border-t border-rule bg-card">
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <div class="flex flex-col gap-8 sm:flex-row sm:justify-between">
            <div class="max-w-sm">
                <p class="font-display text-lg font-bold">Azi<span class="text-mustard">La</span>Pranz</p>
                <p class="mt-2 text-sm text-muted">
                    Restaurante, pizzerii și localuri cu meniul zilei din orașele României.
                </p>
            </div>

            <nav aria-label="Orașe" class="text-sm">
                <p class="mb-2 font-display font-medium">Orașe</p>
                <ul class="grid grid-cols-2 gap-x-6 gap-y-1">
                    @foreach (\App\Support\Cities::all() as $slug => $name)
                        <li>
                            <a class="text-muted hover:text-brick hover:underline"
                               href="{{ url("/{$slug}/restaurante.html") }}">{{ $name }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <nav aria-label="Informații" class="text-sm">
                <p class="mb-2 font-display font-medium">Informații</p>
                <ul class="space-y-1">
                    <li><a class="text-muted hover:text-brick hover:underline" href="{{ url('/despre-noi.html') }}">Despre noi</a></li>
                    <li><a class="text-muted hover:text-brick hover:underline" href="{{ url('/contact.html') }}">Contact</a></li>
                    <li><a class="text-muted hover:text-brick hover:underline" href="{{ url('/politica-de-confidentialitate.html') }}">Confidențialitate</a></li>
                </ul>
            </nav>
        </div>

        <p class="mt-10 border-t border-rule pt-6 text-xs text-muted">
            © {{ date('Y') }} {{ $siteName }}
        </p>
    </div>
</footer>

@stack('scripts')

</body>
</html>

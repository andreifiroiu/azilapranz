@php
    // Legacy location_controller.php:253-254, reproduced character-for-character:
    //   title            = ucfirst(type) . " " . name . ", " . ucwords(city)
    //   meta_description = "Telefon: " . phone . ", Adresa: " . address
    // `type` is the raw comma-separated column, so "Restaurant,pizzerie Foo".
    $title = trim(ucfirst((string) $location->type).' '.$location->name).', '.$cityName;
    $description = 'Telefon: '.$location->phone.', Adresa: '.$location->address;

    // `area` is comma-separated in the legacy data ("Piata Unirii,Centru").
    $area = collect(explode(',', (string) $location->area))
        ->map(fn ($a) => trim($a))
        ->filter()
        ->join(', ');

    $facts = array_filter([
        'Zonă' => $area,
        'Adresă' => $location->address,
        'Program' => $location->hours,
    ]);

    $breadcrumbs = [
        ['name' => $cityName, 'url' => url("/{$citySlug}/restaurante.html")],
        ['name' => $location->name],
    ];

    $schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Restaurant',
        'name' => $location->name,
        'url' => url($location->path),
        'telephone' => $location->phone ?: null,
        'image' => $location->logo_url,
        'servesCuisine' => $location->specific_list ?: null,
        'address' => array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $location->address ?: null,
            'addressLocality' => $cityName,
            'addressCountry' => 'RO',
        ]),
        'geo' => $location->hasCoordinates() ? [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
        ] : null,
        // Suppressed when Google's widget supplies the rating instead — see
        // Location::showsGooglePlace(). Structured ratings must be visible.
        // Also dropped for a closed venue: star snippets are an invitation to
        // visit, and this page exists to say the opposite.
        'aggregateRating' => ($location->average_rating && ! $location->showsGooglePlace() && ! $location->isClosed()) ? [
            '@type' => 'AggregateRating',
            'ratingValue' => $location->average_rating,
            'reviewCount' => $location->rating_votes,
            'bestRating' => 5,
            'worstRating' => 1,
        ] : null,
    ]);

    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Acasă', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $cityName, 'item' => url("/{$citySlug}/restaurante.html")],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $location->name, 'item' => url($location->path)],
        ],
    ];
@endphp

<x-layout :title="$title" :description="$description" :city-slug="$citySlug" :image="$location->logo_url">

    <x-slot:head>
        <script type="application/ld+json">
            {!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
        </script>
        <script type="application/ld+json">
            {!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
        </script>
    </x-slot:head>

    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <x-breadcrumbs :items="$breadcrumbs" />

        @if ($notice = $location->closureNotice())
            {{-- Above the fold and before the venue's own details, so nobody
                 reads the phone number without seeing this first. --}}
            <div role="status"
                 class="mb-8 rounded border border-brick/30 bg-brick/5 px-4 py-3">
                <p class="font-display font-bold text-brick">{{ $notice[0] }}</p>
                <p class="mt-1 text-sm text-muted">{{ $notice[1] }}</p>
            </div>
        @endif

        <article class="border-b border-rule pb-10">
            <div class="flex flex-col gap-6 sm:flex-row sm:items-start">
                @if ($location->logo_url)
                    {{-- Logos are arbitrary aspect ratios, so contain rather than crop. --}}
                    <img src="{{ $location->logo_url }}" alt="{{ $location->name }}"
                         width="150" height="150" decoding="async"
                         class="h-32 w-32 shrink-0 rounded-lg border border-rule bg-card object-contain p-2 sm:h-36 sm:w-36">
                @endif

                <div class="min-w-0">
                    @if ($location->type_list)
                        <p class="flex flex-wrap gap-1.5">
                            @foreach ($location->type_list as $type)
                                <span class="rounded-sm bg-mustard/15 px-2 py-0.5 font-display text-xs font-medium uppercase tracking-wide text-mustard">
                                    {{ $type }}
                                </span>
                            @endforeach
                        </p>
                    @endif

                    {{-- Legacy h1 was the bare venue name (location.tpl:19). --}}
                    <h1 class="mt-2 font-display text-4xl font-bold tracking-tight sm:text-5xl">
                        {{ $location->name }}
                    </h1>

                    <p class="mt-2 font-display text-lg text-muted">
                        {{ ucfirst((string) $location->type) }}, {{ $cityName }}
                    </p>

                    @if ($location->average_rating && ! $location->showsGooglePlace())
                        <p class="mt-3 text-sm text-muted">
                            <span class="font-display text-xl font-bold text-ink">{{ $location->average_rating }}</span>
                            / 5
                            <span class="ml-1">din {{ $location->rating_votes }}
                                {{ $location->rating_votes === 1 ? 'vot' : 'voturi' }}</span>
                        </p>
                    @endif

                    @if ($location->phone)
                        <p class="mt-5">
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $location->phone) }}"
                               class="inline-flex items-center gap-2 rounded bg-ink px-5 py-2.5 font-medium text-paper transition-colors hover:bg-olive">
                                {{ $location->phone }}
                            </a>
                        </p>
                    @endif
                </div>
            </div>
        </article>

        <div class="grid gap-10 py-10 lg:grid-cols-[1fr_20rem]">
            <div class="min-w-0">
                @if ($facts)
                    <h2 class="font-display text-sm font-medium uppercase tracking-widest text-muted">
                        Detalii
                    </h2>
                    <dl class="mt-4 divide-y divide-rule border-y border-rule">
                        @foreach ($facts as $label => $value)
                            <div class="flex gap-4 py-3">
                                <dt class="w-28 shrink-0 text-sm text-muted">{{ $label }}</dt>
                                <dd class="min-w-0 flex-1">{{ $value }}</dd>
                            </div>
                        @endforeach

                        @if ($location->url)
                            <div class="flex gap-4 py-3">
                                <dt class="w-28 shrink-0 text-sm text-muted">Web</dt>
                                <dd class="min-w-0 flex-1">
                                    {{-- Outbound venue links were nofollow on the legacy site. --}}
                                    <a href="{{ $location->url }}" target="_blank" rel="nofollow noopener"
                                       class="break-all text-brick hover:underline">{{ $location->url }}</a>
                                </dd>
                            </div>
                        @endif
                    </dl>
                @endif

                @if ($location->specific_list)
                    <h2 class="mt-10 font-display text-sm font-medium uppercase tracking-widest text-muted">
                        Bucătărie
                    </h2>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($location->specific_list as $item)
                            <li class="rounded border border-rule bg-card px-2.5 py-1 text-sm">{{ $item }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($location->service_list)
                    <h2 class="mt-10 font-display text-sm font-medium uppercase tracking-widest text-muted">
                        Facilități
                    </h2>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($location->service_list as $service)
                            <li class="rounded border border-rule bg-card px-2.5 py-1 text-sm">{{ $service }}</li>
                        @endforeach
                    </ul>
                @endif

                @if (filled($location->description_html))
                    <h2 class="mt-10 font-display text-sm font-medium uppercase tracking-widest text-muted">
                        Despre
                    </h2>
                    <div class="mt-3 max-w-prose leading-relaxed
                                [&_a]:text-brick [&_a]:underline
                                [&_h3]:mt-6 [&_h3]:font-display [&_h3]:text-lg [&_h3]:font-medium
                                [&_li]:mt-1 [&_ol]:mt-3 [&_ol]:list-decimal [&_ol]:pl-6
                                [&_p]:mt-3 [&_strong]:font-semibold
                                [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:pl-6">
                        {{-- Sanitised in LegacyHtml::clean(). --}}
                        {!! $location->description_html !!}
                    </div>
                @endif
            </div>

            <aside>
                {{-- Gated on showsGooglePlace(), which excludes closed venues. --}}
                @if ($location->showsGooglePlace())
                    <x-place-details :place-id="$location->place_id" />
                @endif

                @if ($location->hasCoordinates())
                    <h2 class="font-display text-sm font-medium uppercase tracking-widest text-muted">
                        Hartă
                    </h2>
                    <a class="mt-3 flex items-center justify-center rounded border border-rule bg-card px-4 py-6 text-center text-sm text-brick hover:underline"
                       target="_blank" rel="noopener"
                       href="https://www.google.com/maps/search/?api=1&query={{ $location->latitude }},{{ $location->longitude }}">
                        Deschide în Google Maps
                    </a>
                @endif

                @if ($related->isNotEmpty())
                    <h2 class="mt-10 font-display text-sm font-medium uppercase tracking-widest text-muted">
                        Și în apropiere
                    </h2>
                    <ul class="mt-3 divide-y divide-rule border-y border-rule">
                        @foreach ($related as $other)
                            <li class="py-2.5">
                                <a href="{{ url($other->path) }}"
                                   class="font-display font-medium hover:text-brick hover:underline">{{ $other->name }}</a>
                                @if ($other->area)
                                    <span class="block text-sm text-muted">
                                        {{ collect(explode(',', $other->area))->map(fn ($a) => trim($a))->filter()->join(', ') }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                <p class="mt-8">
                    <a href="{{ url("/{$citySlug}/restaurante.html") }}"
                       class="text-sm text-brick hover:underline">
                        Toate localurile din {{ $cityName }}
                    </a>
                </p>
            </aside>
        </div>
    </div>
</x-layout>

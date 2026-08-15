@php
    // Reproduced verbatim from the legacy city_controller.php so the indexed
    // title and description text does not shift.
    $title = 'Lista restaurantelor din '.$cityName;
    $description = 'Restaurante, fast-food-uri, pub-uri si alte restaurante ce servesc mancare in '.$cityName.'.';
    // The "#" bucket holds names starting with a digit or a diacritic; without
    // it those sections render with nothing in the rail pointing at them.
    $otherBucket = \App\Http\Controllers\CityController::OTHER_BUCKET;
    $letters = array_merge(range('A', 'Z'), [$otherBucket]);

    // Built here rather than inline in the echo below: Laravel 13 has a
    // @context Blade directive, and inline template text is directive-compiled
    // before echoes are, so a literal '@context' key gets replaced with PHP
    // source. The JSON stays syntactically valid, and Google silently discards
    // a block with no @context. @php blocks are extracted first, so this is safe.
    $breadcrumbSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Acasă', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $cityName, 'item' => url()->current()],
        ],
    ];
@endphp

<x-layout :title="$title" :description="$description" :city-slug="$citySlug">

    <x-slot:head>
        <script type="application/ld+json">
            {!! json_encode($breadcrumbSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
        </script>
    </x-slot:head>

    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <x-breadcrumbs :items="[['name' => $cityName]]" />

        <header class="border-b border-rule pb-8">
            <p class="font-display text-sm font-medium uppercase tracking-widest text-mustard">
                {{ $cityName }}
            </p>
            <h1 class="mt-2 font-display text-4xl font-bold tracking-tight sm:text-5xl">
                {{ $title }}
            </h1>
            <p class="mt-4 max-w-2xl text-muted">
                {{ $locations->count() }}
                {{ $locations->count() === 1 ? 'local' : 'localuri' }}
                în {{ $cityName }} — restaurante, pizzerii, pub-uri, fast-food și catering.
            </p>

            <p class="mt-6">
                <a href="{{ url("/{$citySlug}/harta-restaurante.html") }}"
                   class="inline-flex items-center gap-2 rounded border border-ink px-4 py-2 text-sm font-medium transition-colors hover:bg-ink hover:text-paper">
                    Vezi harta restaurantelor
                </a>
            </p>
        </header>

        @if ($locations->isEmpty())
            <p class="py-16 text-muted">Nu avem încă localuri listate în {{ $cityName }}.</p>
        @else
            <div class="gap-10 py-8 lg:grid lg:grid-cols-[10rem_1fr]">

                {{-- A–Z rail. An index is the honest structure for a directory,
                     so it gets the prominence rather than being a decoration. --}}
                <nav aria-label="Index alfabetic" class="mb-8 lg:mb-0">
                    <div class="lg:sticky lg:top-24">
                        <p class="mb-3 font-display text-xs font-medium uppercase tracking-widest text-muted">
                            Index
                        </p>
                        <ul class="flex flex-wrap gap-1 lg:grid lg:grid-cols-6 lg:gap-1">
                            @foreach ($letters as $letter)
                                @php
                                    $has = isset($grouped[$letter]);
                                    // "#" is not usable in a fragment id.
                                    $anchor = $letter === $otherBucket ? 'litera-alte' : 'litera-'.$letter;
                                @endphp
                                <li>
                                    @if ($has)
                                        <a href="#{{ $anchor }}"
                                           title="{{ count($grouped[$letter]) }} localuri"
                                           class="flex h-7 w-7 items-center justify-center rounded font-display text-sm font-medium text-ink transition-colors hover:bg-mustard hover:text-white">
                                            {{ $letter }}
                                        </a>
                                    @else
                                        <span aria-hidden="true"
                                              class="flex h-7 w-7 items-center justify-center font-display text-sm text-rule">{{ $letter }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        {{-- Secondary on small screens: it would push the venues
                             themselves below a second scroll. --}}
                        @if ($areas)
                            <p class="mt-8 mb-3 hidden font-display text-xs font-medium uppercase tracking-widest text-muted lg:block">
                                Zone
                            </p>
                            <ul class="hidden space-y-1 text-sm lg:block">
                                @foreach (array_slice($areas, 0, 14, true) as $area => $count)
                                    <li class="flex justify-between gap-2 text-muted">
                                        <span class="truncate">{{ $area }}</span>
                                        <span class="tabular-nums text-rule">{{ $count }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </nav>

                <div class="min-w-0">
                    @foreach ($grouped as $letter => $group)
                        @php $anchor = $letter === $otherBucket ? 'litera-alte' : 'litera-'.$letter; @endphp
                        <section id="{{ $anchor }}" class="scroll-mt-24">
                            <h2 class="sticky top-[3.6rem] z-10 -mx-4 border-y border-rule bg-paper/95 px-4 py-2 font-display text-2xl font-bold backdrop-blur sm:-mx-6 sm:px-6">
                                {{ $letter }}
                                <span class="ml-2 align-middle text-sm font-medium text-muted">
                                    {{ count($group) }}
                                </span>
                            </h2>

                            <ul class="divide-y divide-rule">
                                @foreach ($group as $location)
                                    <li>
                                        <x-venue-row :location="$location" />
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layout>

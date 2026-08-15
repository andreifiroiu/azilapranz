@php
    // Legacy map_controller.php.
    $title = 'Harta restaurantelor din '.$cityName;
    $description = 'Restaurante, fast-food-uri, pub-uri si alte restaurante ce servesc mancare in '.$cityName.'.';

    $breadcrumbs = [
        ['name' => $cityName, 'url' => url("/{$citySlug}/restaurante.html")],
        ['name' => 'Hartă'],
    ];
@endphp

<x-layout :title="$title" :description="$description" :city-slug="$citySlug">

    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <x-breadcrumbs :items="$breadcrumbs" />

        <header class="border-b border-rule pb-8">
            <p class="font-display text-sm font-medium uppercase tracking-widest text-mustard">
                {{ $cityName }}
            </p>
            <h1 class="mt-2 font-display text-4xl font-bold tracking-tight sm:text-5xl">
                {{ $title }}
            </h1>
            <p class="mt-4 text-muted">
                {{ $mappable->count() }} din {{ $locations->count() }} localuri au coordonate.
            </p>
        </header>

        @if ($mappable->isEmpty())
            <p class="py-16 text-muted">
                Nu avem încă localuri cu coordonate în {{ $cityName }}.
                <a href="{{ url("/{$citySlug}/restaurante.html") }}" class="text-brick hover:underline">
                    Vezi lista completă
                </a>.
            </p>
        @else
            <ul class="grid gap-3 py-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($mappable as $location)
                    <li class="rounded border border-rule bg-card p-4">
                        <h2 class="font-display font-medium">
                            <a href="{{ url($location->path) }}" class="hover:text-brick hover:underline">
                                {{ $location->name }}
                            </a>
                        </h2>
                        @if ($location->address)
                            <p class="mt-1 text-sm text-muted">{{ $location->address }}</p>
                        @endif
                        <a class="mt-2 inline-block text-sm text-brick hover:underline"
                           target="_blank" rel="noopener"
                           href="https://www.google.com/maps/search/?api=1&query={{ $location->latitude }},{{ $location->longitude }}">
                            Deschide în Google Maps
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layout>

@php
    // The legacy homepage was the Timisoara daily-menu page. Its title is kept.
    $title = 'Meniul zilei '.$cityName;
    $names = $featured->pluck('name')->join(', ');
    $description = 'Meniul zilei si alte oferte speciale de la restaurantele din '.$cityName
        .($names ? ': '.$names : '');
@endphp

<x-layout :title="$title" :description="$description" :city-slug="$citySlug" :canonical="url('/')">

    <div class="mx-auto max-w-6xl px-4 sm:px-6">

        <section class="border-b border-rule py-14 sm:py-20">
            <p class="font-display text-sm font-medium uppercase tracking-widest text-mustard">
                Meniul zilei
            </p>
            <h1 class="mt-3 max-w-3xl font-display text-4xl font-bold leading-[1.05] tracking-tight sm:text-6xl">
                Unde mănânci azi<br class="hidden sm:block"> în {{ $cityName }}?
            </h1>
            <p class="mt-5 max-w-xl text-lg text-muted">
                Restaurante, pizzerii, pub-uri și localuri cu meniul zilei —
                adresă, telefon și zonă, dintr-o privire.
            </p>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="{{ url("/{$citySlug}/restaurante.html") }}"
                   class="rounded bg-ink px-5 py-2.5 font-medium text-paper transition-colors hover:bg-olive">
                    Localurile din {{ $cityName }}
                </a>
                <a href="{{ url("/{$citySlug}/harta-restaurante.html") }}"
                   class="rounded border border-ink px-5 py-2.5 font-medium transition-colors hover:bg-ink hover:text-paper">
                    Vezi harta
                </a>
            </div>
        </section>

        <section class="border-b border-rule py-12">
            <h2 class="font-display text-sm font-medium uppercase tracking-widest text-muted">
                Alege orașul
            </h2>
            <ul class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (\App\Support\Cities::all() as $slug => $name)
                    <li>
                        <a href="{{ url("/{$slug}/restaurante.html") }}"
                           class="flex items-baseline justify-between gap-3 rounded border border-rule bg-card px-4 py-3 transition-colors hover:border-ink">
                            <span class="font-display font-medium">{{ $name }}</span>
                            <span class="font-display text-sm tabular-nums text-muted">
                                {{ $counts[$slug] ?? 0 }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        @if ($featured->isNotEmpty())
            <section class="py-12">
                <h2 class="font-display text-sm font-medium uppercase tracking-widest text-muted">
                    Populare în {{ $cityName }}
                </h2>
                <ul class="mt-4 divide-y divide-rule border-y border-rule">
                    @foreach ($featured as $location)
                        <li><x-venue-row :location="$location" /></li>
                    @endforeach
                </ul>
                <p class="mt-6">
                    <a href="{{ url("/{$citySlug}/restaurante.html") }}"
                       class="text-brick hover:underline">
                        Vezi toate localurile din {{ $cityName }}
                    </a>
                </p>
            </section>
        @endif
    </div>
</x-layout>

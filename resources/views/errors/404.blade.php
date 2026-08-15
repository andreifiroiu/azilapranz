@php
    // Real 404s. The legacy served HTTP 200 with homepage content for every
    // unknown URL, which registers as a soft 404 in Search Console.
@endphp

<x-layout title="Pagina nu a fost găsită" robots="noindex, follow">
    <div class="mx-auto max-w-3xl px-4 py-20 text-center sm:px-6">
        <p class="font-display text-sm font-medium uppercase tracking-widest text-mustard">Eroare 404</p>
        <h1 class="mt-3 font-display text-4xl font-bold tracking-tight sm:text-5xl">
            Pagina nu a fost găsită
        </h1>
        <p class="mt-4 text-muted">
            Linkul e greșit sau pagina nu mai există.
        </p>

        <ul class="mt-10 flex flex-wrap justify-center gap-3">
            @foreach (\App\Support\Cities::all() as $slug => $name)
                <li>
                    <a href="{{ url("/{$slug}/restaurante.html") }}"
                       class="rounded border border-rule bg-card px-4 py-2 text-sm transition-colors hover:border-ink">
                        {{ $name }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layout>

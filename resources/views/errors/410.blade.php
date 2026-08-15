@php
    // Suspended venues. The legacy returned HTTP 200 with the body text
    // "Ne pare rau, pagina acestui restaurant nu mai exista." — a soft 404.
    // 410 tells crawlers the removal is deliberate and permanent.
@endphp

<x-layout title="Localul nu mai există" robots="noindex, follow">
    <div class="mx-auto max-w-3xl px-4 py-20 text-center sm:px-6">
        <p class="font-display text-sm font-medium uppercase tracking-widest text-mustard">Eroare 410</p>
        <h1 class="mt-3 font-display text-4xl font-bold tracking-tight sm:text-5xl">
            Localul nu mai există
        </h1>
        <p class="mt-4 text-muted">
            Ne pare rău, pagina acestui restaurant a fost retrasă.
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

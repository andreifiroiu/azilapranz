@props(['placeId' => null])

@php
    $key = config('azp.google.maps_browser_key');
@endphp

@if (\App\Support\GooglePlaces::uiKitEnabled() && filled($placeId))
    {{--
        Places UI Kit. Google fetches and renders the live content — hours,
        photos, ratings, reviews — inside a shadow root, and supplies its own
        attribution. Nothing reaches our database, which is the point: EEA
        Service Specific Terms §15.3 exempts the UI Kit from both the Permitted
        Uses whitelist and the §15.1 no-map rule that put raw Places API
        content out of reach for a directory like this one.

        Do not read values out of this element and store them. That would be
        the Places API path again, without the exemption.
    --}}
    {{-- mb-10 so the sibling sections in the aside keep their rhythm. --}}
    <div class="mb-10">
        <h2 class="font-display text-sm font-medium uppercase tracking-widest text-muted">
            Pe Google
        </h2>

        {{-- Below 160px the element misrenders, so the aside's 20rem column is
             the floor; on mobile it spans the full width. --}}
        <gmp-place-details class="mt-3 block w-full min-w-[180px]">
            <gmp-place-details-place-request place-id="{{ $placeId }}"></gmp-place-details-place-request>
        </gmp-place-details>
    </div>

    @once
        @push('scripts')
            {{-- Google's official inline bootstrap loader, verbatim apart from
                 the parameters. It defines the gmp-* custom elements on demand. --}}
            <script>
                (g => { var h, a, k, p = "The Google Maps JavaScript API", c = "google", l = "importLibrary", q = "__ib__", m = document, b = window; b = b[c] || (b[c] = {}); var d = b.maps || (b.maps = {}), r = new Set, e = new URLSearchParams, u = () => h || (h = new Promise(async (f, n) => { await (a = m.createElement("script")); e.set("libraries", [...r] + ""); for (k in g) e.set(k.replace(/[A-Z]/g, t => "_" + t[0].toLowerCase()), g[k]); e.set("callback", c + ".maps." + q); a.src = `https://maps.${c}apis.com/maps/api/js?` + e; d[q] = f; a.onerror = () => h = n(Error(p + " could not load.")); a.nonce = m.querySelector("script[nonce]")?.nonce || ""; m.head.append(a) })); d[l] ? console.warn(p + " only loads once. Ignoring:", g) : d[l] = (f, ...n) => r.add(f) && u().then(() => d[l](f, ...n)) })({
                    key: @json($key),
                    v: 'weekly',
                    language: 'ro',
                    region: 'RO',
                });
                google.maps.importLibrary('places');
            </script>
        @endpush
    @endonce
@endif

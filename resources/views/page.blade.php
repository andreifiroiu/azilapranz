<x-layout :title="$title" :description="$metaDescription">

    <div class="mx-auto max-w-3xl px-4 sm:px-6">

        <x-breadcrumbs :items="[['name' => $title]]" />

        <article class="pb-16">
            <h1 class="border-b border-rule pb-6 font-display text-4xl font-bold tracking-tight sm:text-5xl">
                {{ $title }}
            </h1>

            <div class="mt-8 max-w-prose leading-relaxed
                        [&_a]:text-brick [&_a]:underline
                        [&_h2]:mt-8 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-bold
                        [&_h3]:mt-6 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-medium
                        [&_li]:mt-1 [&_ol]:mt-4 [&_ol]:list-decimal [&_ol]:pl-6
                        [&_p]:mt-4 [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:pl-6">
                @if ($page && filled($page->content_html))
                    {{-- Sanitised in LegacyHtml::clean(). --}}
                    {!! $page->content_html !!}
                @else
                    <p>
                        Această pagină este în curs de actualizare.
                        Între timp poți vedea
                        <a href="{{ url('/'.config('azp.default_city').'/restaurante.html') }}">lista localurilor</a>.
                    </p>
                @endif

                {{-- The legacy row behind this URL predates the site's
                     analytics and covers only the Facebook bot, so the cookie
                     terms live here rather than in the pages table — which
                     ImportLegacyCommand deletes and re-inserts wholesale on
                     every run, taking any hand-edit with it. Gated on the same
                     master switch as the tag and the banner: with no
                     measurement there are no analytics cookies to describe. --}}
                @if ($name === 'politica-de-confidentialitate' && config('azp.analytics_id'))
                    @php
                        // Read from config rather than retyped: bumping the
                        // cookie name or the six months would otherwise leave a
                        // published legal page stating something untrue, with
                        // nothing to flag it.
                        $consentCookie = config('azp.consent.cookie');
                        $consentMonths = (int) round(config('azp.consent.max_age_days') / 30.42);
                        $consentRequired = (bool) config('azp.consent.enabled');
                    @endphp

                    <h2>Cookie-uri</h2>

                    <p>
                        Cookie-urile sunt fișiere mici pe care site-ul le pune în browserul tău.
                        Le folosim în două scopuri, tratate diferit.
                    </p>

                    <h3>Cookie-uri strict necesare</h3>

                    <p>
                        Fac site-ul să funcționeze și se pun fără să îți cerem acordul,
                        pentru că fără ele nu ai putea folosi paginile.
                    </p>

                    <ul>
                        @if ($consentRequired)
                            <li><strong>{{ $consentCookie }}</strong> — reține răspunsul tău la banner-ul de cookie-uri, ca să nu te întrebăm la fiecare pagină. Durează {{ $consentMonths }} luni.</li>
                        @endif
                        <li><strong>Cookie-ul de sesiune</strong> — ține minte contextul vizitei tale pe durata acesteia.</li>
                    </ul>

                    <h3>Cookie-uri de statistică</h3>

                    <p>
                        Folosim Google Analytics 4 ca să vedem câți vizitatori avem și ce pagini
                        citesc. Ne interesează cifrele pe ansamblu, nu persoanele.
                        @if ($consentRequired)
                            <strong>Aceste cookie-uri se pun doar dacă apeși „Accept” în banner.</strong>
                            Dacă refuzi sau ignori banner-ul, nu se pune niciunul.
                        @else
                            <strong>Aceste cookie-uri se pun la prima ta vizită.</strong>
                            Mai jos îți arătăm cum le poți bloca sau șterge.
                        @endif
                    </p>

                    <ul>
                        <li><strong>_ga</strong> și <strong>_ga_&lt;identificator&gt;</strong> — deosebesc un vizitator de altul printr-un identificator aleatoriu. Durează până la 2 ani.</li>
                    </ul>

                    <p>
                        Google Analytics nu stochează adresa ta IP, iar noi nu îi transmitem
                        numele, adresa de e-mail sau alte date prin care ai putea fi identificat
                        direct. Google păstrează datele la nivel de utilizator cel mult 14 luni.
                        Detalii despre cum prelucrează Google aceste date găsești în
                        <a href="https://policies.google.com/privacy" rel="nofollow noopener" target="_blank">politica de confidențialitate Google</a>.
                    </p>

                    @if ($consentRequired)
                        <h3>Cum îți retragi acordul</h3>

                        <p>
                            Temeiul legal pentru cookie-urile de statistică este consimțământul tău,
                            iar retragerea lui trebuie să fie la fel de simplă ca acordarea.
                            Apasă <strong>„Setări cookie-uri”</strong> în subsolul oricărei pagini:
                            banner-ul reapare și poți alege altfel. Dacă alegi „Refuz”, ștergem pe loc
                            cookie-urile Google deja puse în browserul tău.
                        </p>

                        <p>
                            Te întrebăm din nou după {{ $consentMonths }} luni, ca alegerea ta să rămână una recentă.
                            Poți de asemenea șterge sau bloca cookie-urile direct din setările
                            browserului, pentru acest site sau pentru toate.
                        </p>
                    @else
                        <h3>Cum le poți refuza</h3>

                        <p>
                            Poți șterge sau bloca cookie-urile direct din setările browserului,
                            pentru acest site sau pentru toate. Google oferă și un
                            <a href="https://tools.google.com/dlpage/gaoptout" rel="nofollow noopener" target="_blank">supliment de browser</a>
                            care dezactivează Google Analytics pe orice site.
                        </p>
                    @endif
                @endif
            </div>
        </article>
    </div>
</x-layout>

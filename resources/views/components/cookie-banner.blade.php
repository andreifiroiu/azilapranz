@php
    $consent = config('azp.consent');
@endphp

@if (config('azp.analytics_id'))
    {{--
        Cookie consent bar. Rendered only when there is a tag to consent to,
        which is why it is absent locally and in the suite unless a test opts in.

        It ships with the `hidden` attribute and is revealed by the script
        directly below, and only when no decision is stored. The other way round
        — visible by default, hidden by script — flashes the bar at every
        returning visitor who already answered. Tailwind's preflight gives
        [hidden] `display: none !important`, and every display utility here sits
        on the inner wrapper anyway, so the attribute cannot lose.

        Not a dialog and not a focus trap. It does not block the page, and
        trapping the keyboard inside a bar the visitor is entitled to ignore
        would be a bigger accessibility failure than the one it solves. It is a
        named region landmark at the very end of the document — after the footer
        in DOM order, and therefore last in tab order too, which matches where
        it sits on screen.

        There is no dismiss button and Escape is not bound: both would close the
        bar without recording an answer, and silence must not be read as either
        one. Two explicit choices, no ambiguous exit.

        z-40, the same layer as the sticky header; they never meet, one is
        pinned to the top and this to the bottom. z-50 stays reserved for the
        skip link.

        No entrance animation: the prefers-reduced-motion block in app.css would
        neutralise it for the visitors who most need the bar to hold still, and
        a transition would fight the hidden attribute anyway.
    --}}
    <section id="consimtamant-cookie" hidden tabindex="-1"
             aria-label="Consimțământ pentru cookie-uri"
             class="fixed inset-x-0 bottom-0 z-40 border-t border-rule bg-card">
        <div class="mx-auto flex max-w-6xl flex-col gap-4 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p class="max-w-2xl text-sm text-muted">
                Folosim cookie-uri pentru statistici anonime de trafic (Google Analytics).
                Le activăm doar dacă ești de acord. Detalii în
                <a class="text-ink underline underline-offset-2 hover:text-brick"
                   href="{{ url('/politica-de-confidentialitate.html') }}">politica de confidențialitate</a>.
            </p>

            {{-- Identical styling on both buttons, deliberately. The house
                 pattern pairs a filled primary with an outlined secondary, but
                 here the two answers have to be equally easy to give: neither
                 gets to look like the expected one. --}}
            <div class="flex shrink-0 gap-3">
                <button type="button" id="consimtamant-accept"
                        class="cursor-pointer rounded border border-ink px-5 py-2 text-sm font-medium transition-colors hover:bg-ink hover:text-paper">
                    Accept
                </button>
                <button type="button" id="consimtamant-refuz"
                        class="cursor-pointer rounded border border-ink px-5 py-2 text-sm font-medium transition-colors hover:bg-ink hover:text-paper">
                    Refuz
                </button>
            </div>
        </div>
    </section>

    {{-- Inline rather than @push('scripts'): the reveal has to run immediately
         after the markup it reveals, and that must not depend on where a stack
         happens to render. --}}
    <script>
        (function () {
            var bar = document.getElementById('consimtamant-cookie');
            var accept = document.getElementById('consimtamant-accept');
            var refuse = document.getElementById('consimtamant-refuz');
            var reopen = document.getElementById('consimtamant-setari');
            var message = bar.querySelector('p');
            var cookieName = @json($consent['cookie']);
            var version = {{ (int) $consent['version'] }};
            var maxAge = {{ (int) $consent['max_age_days'] }} * 86400;
            var returnFocusTo = null;

            // The bar is fixed to the bottom, so without a matching padding it
            // covers the last rows of a long listing — including the footer link
            // that reopens it. Its height is not settled when it first appears:
            // the web fonts land after this script runs and rewrap the copy, and
            // a resize or rotation rewraps it again. Measuring once leaves a
            // strip of dead space under the footer, so track the real height.
            var trackHeight = window.ResizeObserver ? new ResizeObserver(syncPadding) : null;

            function syncPadding() {
                // offsetHeight is 0 while the bar is hidden, which is exactly the
                // padding a hidden bar should reserve.
                document.body.style.paddingBottom = bar.offsetHeight + 'px';
            }

            function show(moveFocus) {
                bar.removeAttribute('hidden');
                syncPadding();

                // Without ResizeObserver the measurement above is all we get,
                // which is the behaviour every browser had before 2020.
                if (trackHeight) trackHeight.observe(bar);

                if (moveFocus) bar.focus();
            }

            function hide() {
                bar.setAttribute('hidden', '');

                if (trackHeight) trackHeight.unobserve(bar);
                document.body.style.paddingBottom = '';

                if (returnFocusTo) {
                    returnFocusTo.focus();
                    returnFocusTo = null;
                }
            }

            // The last two labels of the host. Correct for a .ro domain; it
            // would not be for a .co.uk one, so this is not a general-purpose
            // helper. Null where a domain attribute cannot apply at all — a
            // host with no dot (localhost) or a bare IP — because a domain the
            // browser rejects stores nothing, silently.
            function registrableDomain() {
                var host = location.hostname;

                if (host.indexOf('.') === -1) return null;
                if (host.indexOf(':') !== -1 || /^[0-9.]+$/.test(host)) return null;

                return host.split('.').slice(-2).join('.');
            }

            /** @return {boolean} whether the decision was actually persisted. */
            function remember(answer) {
                var attributes = '=' + version + ':' + answer
                    + '; Max-Age=' + maxAge + '; path=/; SameSite=Lax'
                    + (location.protocol === 'https:' ? '; Secure' : '');

                // Scope the decision the way GA scopes _ga: on the registrable
                // domain, so the apex and www share one answer. They are both
                // live and uncanonicalised today (see the deployment reminders
                // in .claude/ship-it.md), and a host-only cookie would mean
                // accepting on www is invisible on the apex — while refusing on
                // the apex would wipe _ga cookies granted on www.
                var domain = registrableDomain();

                if (domain) {
                    document.cookie = cookieName + attributes + '; domain=.' + domain;

                    if (read()) return true;
                }

                document.cookie = cookieName + attributes;

                return !!read();
            }

            // Reuse the head's reader rather than parsing the cookie again here;
            // two parsers are how the read key and the write key drift apart.
            function read() {
                return typeof window.azpReadConsent === 'function' ? window.azpReadConsent() : null;
            }

            function decide(answer) {
                var remembered = remember(answer);

                if (typeof window.gtag === 'function') {
                    window.gtag('consent', 'update', {
                        analytics_storage: answer === 'granted' ? 'granted' : 'denied',
                    });
                }

                // A consent update stops future writes but leaves the _ga
                // cookies already on the device, which is not what withdrawing
                // consent is supposed to mean.
                if (answer === 'denied') forgetGoogleCookies();

                window.azpConsent = answer;

                // Writing a cookie fails silently when the browser blocks them —
                // no exception, no return value. Hiding the bar anyway would
                // tell the visitor their choice was recorded while it was not,
                // and they would be asked again on every page with nothing
                // explaining why. The choice still applies to this page load.
                if (!remembered) {
                    message.textContent = 'Alegerea ta se aplică pe această pagină, dar browserul '
                        + 'blochează cookie-urile, așa că nu o putem reține — te vom întreba din nou.';

                    return;
                }

                hide();
            }

            // GA sets its cookies on the registrable domain, so the exact host
            // is not enough — ".azilapranz.ro" has to be cleared too.
            function forgetGoogleCookies() {
                var host = location.hostname;
                var domain = registrableDomain();
                var domains = ['', host, '.' + host].concat(domain ? ['.' + domain] : []);

                document.cookie.split(';').forEach(function (cookie) {
                    var name = cookie.split('=')[0].trim();

                    if (name.indexOf('_ga') !== 0) return;

                    domains.forEach(function (scope) {
                        document.cookie = name + '=; Max-Age=0; path=/'
                            + (scope ? '; domain=' + scope : '');
                    });
                });
            }

            // Asking comes first, and on its own. Everything below this point is
            // convenience — the two buttons' handlers, the footer's reopen
            // control — and a throw in any of it (a missing element after a
            // markup change, say) would otherwise abort the rest of this script
            // and take the reveal with it. The bar would then never appear for
            // anyone, nobody would ever be asked, and the only trace would be a
            // console error no visitor reads. Order it so the mandatory step
            // cannot be killed by an optional one.
            //
            // No stored answer means the question was never put, or the answer
            // has aged out of its six months.
            if (!window.azpConsent) show(false);

            accept.addEventListener('click', function () { decide('granted'); });
            refuse.addEventListener('click', function () { decide('denied'); });

            // Withdrawing consent has to be as easy as giving it (GDPR art.
            // 7(3)), so the footer brings the bar back. Its list item ships
            // hidden and is revealed here, so it is never a dead control for a
            // visitor without JavaScript — who never sees the bar either.
            var reopenItem = reopen && reopen.closest('li');

            if (reopenItem) {
                reopenItem.removeAttribute('hidden');

                reopen.addEventListener('click', function () {
                    returnFocusTo = reopen;
                    // Focus moves only here, where the visitor asked for the
                    // bar. On first load it would yank the caret out of the page
                    // for something nobody requested.
                    show(true);
                });
            }
        })();
    </script>
@endif

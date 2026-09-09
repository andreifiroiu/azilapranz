@php
    $analyticsId = config('azp.analytics_id');
    $consent = config('azp.consent');
    $consentRequired = (bool) ($consent['enabled'] ?? true);
@endphp

@if ($analyticsId)
    @if (! $consentRequired)
        {{--
            Consent gate switched off (azp.consent.enabled): gtag.js loads and
            measures immediately, exactly as Google's own snippet does. No
            consent commands are queued at all — emitting `default` granted
            would be a claim we had asked and been told yes.

            This sets _ga on every visitor with no prior consent. For an EU
            audience that is what ePrivacy forbids, so it belongs to a staging
            box or a non-EU deployment, not to azilapranz.ro. The banner, the
            footer withdrawal control and the cookie section of the privacy
            policy all disappear with it — there is no half-on state where the
            site claims to ask and does not.
        --}}
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}

            gtag('js', new Date());
            gtag('config', @json($analyticsId));
        </script>
    @else
    {{--
        Google tag (gtag.js) under Consent Mode v2.

        The order below is the point of this block, and it is not the order
        Google's copy-paste snippet gives you:

        1. the dataLayer shim, so `gtag` exists for everything under it —
           including the banner at the foot of the page. gtag.js does not define
           this function, the page does, and components/cookie-banner reuses it;
        2. `consent default`, denied. It has to be queued before the library
           reads the queue, or the first page_view of the visit is collected
           with storage still open — the exact thing this feature exists to
           prevent;
        3. the stored decision, replayed as a `consent update` *before* `config`,
           so a returning visitor who accepted is measured properly on their
           first hit rather than one page late;
        4. `config`;
        5. the loader, last. It is async: placed above this block it may finish
           downloading and execute first, processing `config` with no consent
           state queued at all. Async cannot interrupt a script that is already
           running, so keeping it below is what makes the order above a
           guarantee rather than a hope.

        No `wait_for_update`: the update in step 3 is synchronous, so there is
        nothing to wait for and every visitor would pay the delay.

        ad_storage stays denied even on acceptance — the site runs no Google ad
        products. If that ever changes, the banner copy has to change with it,
        and azp.consent.version has to be bumped to re-ask everyone.
    --}}
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}

        gtag('consent', 'default', {
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied',
            analytics_storage: 'denied',
            functionality_storage: 'granted',
            security_storage: 'granted',
        });

        // The one reader of the consent cookie. The banner calls this too, for
        // its write read-back, rather than parsing the cookie a second time —
        // two parsers drifting apart is exactly the failure config/azp.php warns
        // about, and it would look like "the banner comes back on every page".
        //
        // Returns 'granted', 'denied', or null for "never asked". Expiry is the
        // cookie's own Max-Age, so a decision past its six months is simply not
        // there to read. The version prefix is separate: it invalidates
        // decisions still in date that no longer cover what we run.
        //
        // The name is built into the regex rather than interpolated as a
        // literal: inside a <script> element entities are not decoded, so an
        // HTML-escaped character would land in the pattern verbatim and silently
        // never match, and an unescaped `.` would match any character.
        window.azpReadConsent = function () {
            try {
                var name = @json($consent['cookie']).replace(/[.*+?^${}()|[\]\\-]/g, '\\$&');
                var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));

                if (!match) return null;

                var parts = decodeURIComponent(match[1]).split(':');

                if (parts[0] !== '{{ (int) $consent['version'] }}') return null;

                // Anything we do not recognise is "never asked", not "denied".
                // Reading a malformed value as a decision would lock the banner
                // off for good: the visitor could never be asked again, on the
                // strength of an answer they never gave.
                if (parts[1] === 'granted') return 'granted';
                if (parts[1] === 'denied') return 'denied';

                return null;
            } catch (e) {
                // document.cookie throws SecurityError in a sandboxed iframe and
                // decodeURIComponent throws URIError on a malformed percent
                // sequence left by anything else on the domain. An uncaught
                // throw here would abort the rest of this script, including the
                // config call below.
                return null;
            }
        };

        window.azpConsent = window.azpReadConsent();

        if (window.azpConsent === 'granted') {
            gtag('consent', 'update', { analytics_storage: 'granted' });
        }

        gtag('js', new Date());
        gtag('config', @json($analyticsId));
    </script>
    @endif

    {{-- Outside the branch: whichever block ran above, the async loader has to
         come after it. Above an inline script it can execute first and process
         `config` with no state queued. --}}
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $analyticsId }}"></script>
@endif

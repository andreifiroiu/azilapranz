<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only Google Places client in the app, and deliberately a narrow one.
 *
 * The field masks here are constants, and responses are reduced to an ID and a
 * status before they leave this class. Maps Platform General Service Terms §3
 * permits storing `place_id` indefinitely; EEA ToS §3.3.2(a) forbids saving
 * business names, addresses or reviews, and §3.3.2(b) forbids caching anything
 * else. So a widened mask is not a free optimisation — whatever it returns
 * becomes reachable by callers that persist what they are handed.
 *
 * One deliberate exception: `refresh()` requests `businessStatus` and maps it
 * onto CLOSED_PERMANENTLY / CLOSED_TEMPORARILY, which the caller stores. That
 * is a cache of Google Maps Content and is not covered by §3 — it was asked
 * for knowingly, to catch closures that a still-resolving place ID hides.
 * Nothing else from the response is exposed.
 *
 * Fresh Google content belongs on the page via Places UI Kit
 * (`<x-place-details>`), which renders client-side and stores nothing.
 */
class GooglePlaces
{
    private const BASE = 'https://places.googleapis.com/v1';

    /**
     * Google has the place, and reports the business as trading.
     *
     * Only refresh() may set this, because only refresh() asks for
     * `businessStatus`. It is the condition azp:places:sync-status reopens a
     * venue on, so a search — which happily returns closed restaurants and
     * cannot see their trading status — must never claim it.
     */
    public const OK = 'ok';

    /**
     * A search matched this venue to a place ID; whether the business trades
     * is not yet known. The next refresh() replaces this with the truth.
     */
    public const RESOLVED = 'resolved';

    /** Place ID no longer resolves — venue closed, moved or merged away. */
    public const NOT_FOUND = 'not_found';

    /** Stored ID is malformed; it needs re-resolving from a search. */
    public const INVALID = 'invalid';

    /** Search returned nothing for this venue. */
    public const UNMATCHED = 'unmatched';

    /** Google reports the business as permanently closed. */
    public const CLOSED_PERMANENTLY = 'closed_permanently';

    /** Google reports the business as temporarily closed. */
    public const CLOSED_TEMPORARILY = 'closed_temporarily';

    /**
     * Statuses that put a venue on the review list.
     *
     * Ordered by how strongly each implies the venue should come down, which
     * is the order the alert email groups them in.
     *
     * @var list<string>
     */
    public const NEEDS_REVIEW = [
        self::CLOSED_PERMANENTLY,
        self::NOT_FOUND,
        self::CLOSED_TEMPORARILY,
        self::INVALID,
    ];

    /**
     * The call failed for a reason that says nothing about the venue — a rate
     * limit, a timeout, an outage. Callers must leave the record alone,
     * including `place_id_checked_at`, so the next run picks it up again.
     *
     * A DEFERRED result always carries `place_id => null`; use
     * `self::isDeferred()` and skip the write rather than reading the ID.
     */
    public const DEFERRED = 'deferred';

    /** @param  array{status: string, place_id: ?string}  $result */
    public static function isDeferred(array $result): bool
    {
        return $result['status'] === self::DEFERRED;
    }

    public function __construct(private readonly ?string $key = null) {}

    public static function make(): self
    {
        return new self(config('azp.google.places_key'));
    }

    public function configured(): bool
    {
        return filled($this->key);
    }

    /**
     * Whether the venue page may render the Places UI Kit widget.
     *
     * Static because the views and the model need the same answer, and it
     * depends only on config — see `<x-place-details>`.
     */
    public static function uiKitEnabled(): bool
    {
        return (bool) config('azp.google.ui_kit')
            && filled(config('azp.google.maps_browser_key'));
    }

    /**
     * Resolve a venue to a place ID via Text Search (New).
     *
     * Coordinates, where we have them, bias the search — Romanian venue names
     * repeat across cities and the address alone is often ambiguous.
     *
     * @return array{status: string, place_id: ?string}
     */
    public function search(Location $location): array
    {
        $query = collect([$location->name, $location->address, $location->city])
            ->filter()
            ->join(', ');

        $body = [
            'textQuery' => $query,
            'languageCode' => 'ro',
            'regionCode' => 'RO',
            'maxResultCount' => 1,
        ];

        if ($location->hasCoordinates()) {
            $body['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => (float) $location->latitude,
                        'longitude' => (float) $location->longitude,
                    ],
                    'radius' => 2000.0,
                ],
            ];
        }

        try {
            $response = $this->request('places.id')
                ->post(self::BASE.'/places:searchText', $body);
        } catch (ConnectionException $e) {
            // retry(throw: false) suppresses error *responses* only; a timeout,
            // DNS failure or reset still throws. Left uncaught it would abort a
            // 500-venue run partway through, which is exactly the class of
            // failure DEFERRED exists to absorb.
            Log::warning('Places search failed to connect', [
                'location_id' => $location->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::DEFERRED, 'place_id' => null];
        }

        if (! $response->successful()) {
            // Only a clean empty result means "no such place". Anything else is
            // our problem, not the restaurant's.
            Log::warning('Places search returned an error', [
                'location_id' => $location->id,
                'status' => $response->status(),
            ]);

            return ['status' => self::DEFERRED, 'place_id' => null];
        }

        // Only `id` was requested, so `id` is all there is to read.
        $id = $response->json('places.0.id');

        return filled($id)
            ? ['status' => self::RESOLVED, 'place_id' => $id]
            : ['status' => self::UNMATCHED, 'place_id' => null];
    }

    /**
     * Re-check a stored place ID.
     *
     * Two signals, because they catch different failures. A place ID that
     * stops resolving means the place was removed from Google's database;
     * `businessStatus` catches the commoner case where the place still
     * resolves perfectly well and the restaurant behind it has shut.
     *
     * A place that has merged into another returns the replacement ID, so the
     * caller should persist whatever comes back rather than assume it is
     * unchanged.
     *
     * Billing note: `businessStatus` is a Place Details **Pro** field. Asking
     * for it moves this call off the free unlimited "IDs Only" SKU and onto
     * Pro — 5,000 free calls a month, then $17 per 1,000.
     *
     * @return array{status: string, place_id: ?string}
     */
    public function refresh(string $placeId): array
    {
        if (self::malformed($placeId)) {
            return ['status' => self::INVALID, 'place_id' => null];
        }

        try {
            // Encoded: a stored ID carrying a space or slash would otherwise
            // build a malformed URI and throw InvalidArgumentException, which
            // is not a ConnectionException and would abort the whole run.
            $response = $this->request('id,businessStatus')
                ->get(self::BASE.'/places/'.rawurlencode($placeId));
        } catch (ConnectionException $e) {
            Log::warning('Places refresh failed to connect', [
                'place_id' => $placeId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::DEFERRED, 'place_id' => null];
        }

        if ($response->successful()) {
            // A 200 whose body did not parse tells us nothing. Without this a
            // proxy interstitial or a reshaped response would read as
            // "trading" for every venue and reopen every closure at once.
            if (! is_array($response->json())) {
                Log::warning('Places refresh returned an unparseable body', [
                    'place_id' => $placeId,
                    'body' => str($response->body())->limit(200)->value(),
                ]);

                return ['status' => self::DEFERRED, 'place_id' => null];
            }

            // The place still resolves, so the ID stays either way — a closed
            // restaurant is not a missing place, and re-searching for it next
            // run would just burn a billed call to find the same thing.
            $id = $response->json('id') ?: $placeId;

            return [
                'status' => match ($response->json('businessStatus')) {
                    'CLOSED_PERMANENTLY' => self::CLOSED_PERMANENTLY,
                    'CLOSED_TEMPORARILY' => self::CLOSED_TEMPORARILY,
                    'OPERATIONAL' => self::OK,
                    // Absent or unrecognised. Google omits the field for places
                    // it holds no trading status for, so this is not a closure
                    // — but it is not a confirmation either, and OK is what
                    // sync-status reopens a venue on. Identity known, trading
                    // unknown is exactly what RESOLVED means.
                    default => self::RESOLVED,
                },
                'place_id' => $id,
            ];
        }

        return match ($response->status()) {
            404 => ['status' => self::NOT_FOUND, 'place_id' => null],
            400 => $this->classifyBadRequest($response, $placeId),
            // 429 and 5xx say nothing about the venue, so the record is left
            // untouched and re-checked next run rather than counted as done.
            // place_id is null like every other DEFERRED result: a caller that
            // forgot to skip the write must not be handed an ID to persist.
            default => ['status' => self::DEFERRED, 'place_id' => null],
        };
    }

    /**
     * A 400 is never treated as a fact about the venue.
     *
     * Places returns INVALID_ARGUMENT both for a malformed place ID and for a
     * malformed field mask, and the second is identical for every venue in the
     * run — classifying it per-venue would mark the whole directory INVALID and
     * wipe every stored place ID in one night. Reading the error text cannot
     * separate them either: a field-mask rejection names
     * `google.maps.places.v1.Place`, so any match on "place" catches it.
     *
     * A genuinely malformed ID is caught before the call instead, by
     * self::malformed(), where the answer is certain and costs nothing.
     *
     * @return array{status: string, place_id: ?string}
     */
    private function classifyBadRequest(Response $response, string $placeId): array
    {
        Log::warning('Places refresh returned 400 — treating it as our request, not the venue', [
            'place_id' => $placeId,
            'error' => (string) $response->json('error.message'),
        ]);

        return ['status' => self::DEFERRED, 'place_id' => null];
    }

    /**
     * Whether a stored ID cannot possibly be a place ID.
     *
     * Google place IDs are URL-safe base64-ish tokens. Anything outside that
     * alphabet came from a bad write or a legacy import, and there is no point
     * spending a billed call to be told so.
     */
    private static function malformed(string $placeId): bool
    {
        return $placeId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $placeId) !== 1;
    }

    private function request(string $fieldMask): PendingRequest
    {
        return Http::asJson()
            ->timeout(15)
            // Retry only what retrying can fix. A 4xx is deterministic — the
            // same request gets the same answer — so retrying one tripled the
            // billed Place Details Pro calls a permanently rejected ID cost
            // every night, for nothing.
            ->retry(2, 500, throw: false, when: fn (\Throwable $e) => ! $e instanceof RequestException
                || $e->response->serverError()
                || $e->response->status() === 429)
            ->withHeaders([
                'X-Goog-Api-Key' => (string) $this->key,
                'X-Goog-FieldMask' => $fieldMask,
            ]);
    }
}

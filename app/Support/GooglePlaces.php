<?php

namespace App\Support;

use App\Models\Location;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only Google Places client in the app, and deliberately a narrow one.
 *
 * Every request here sends a field mask of `id` alone. That is not an
 * optimisation — it is the compliance boundary. Maps Platform General Service
 * Terms §3 permits storing `place_id` indefinitely, while EEA ToS §3.3.2(a)
 * forbids saving business names, addresses or reviews and §3.3.2(b) forbids
 * caching anything else. Widening a field mask here would put venue data in
 * reach of the callers, so the masks are constants and the responses are
 * reduced to an ID and a status before they leave this class.
 *
 * Fresh Google content belongs on the page via Places UI Kit
 * (`<x-place-details>`), which renders client-side and stores nothing.
 */
class GooglePlaces
{
    private const BASE = 'https://places.googleapis.com/v1';

    /** Venue matched to a place ID. */
    public const OK = 'ok';

    /** Place ID no longer resolves — venue closed, moved or merged away. */
    public const NOT_FOUND = 'not_found';

    /** Stored ID is malformed; it needs re-resolving from a search. */
    public const INVALID = 'invalid';

    /** Search returned nothing for this venue. */
    public const UNMATCHED = 'unmatched';

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
            ? ['status' => self::OK, 'place_id' => $id]
            : ['status' => self::UNMATCHED, 'place_id' => null];
    }

    /**
     * Re-check a stored place ID.
     *
     * Google documents an ID-only Place Details request as free, and as the
     * supported way to refresh IDs older than 12 months. A place that has
     * merged into another returns the replacement ID, so the caller should
     * persist whatever comes back rather than assume it is unchanged.
     *
     * @return array{status: string, place_id: ?string}
     */
    public function refresh(string $placeId): array
    {
        try {
            $response = $this->request('id')
                ->get(self::BASE.'/places/'.$placeId);
        } catch (ConnectionException $e) {
            Log::warning('Places refresh failed to connect', [
                'place_id' => $placeId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::DEFERRED, 'place_id' => null];
        }

        if ($response->successful()) {
            return ['status' => self::OK, 'place_id' => $response->json('id') ?: $placeId];
        }

        return match ($response->status()) {
            404 => ['status' => self::NOT_FOUND, 'place_id' => null],
            400 => ['status' => self::INVALID, 'place_id' => null],
            // 429 and 5xx say nothing about the venue, so the record is left
            // untouched and re-checked next run rather than counted as done.
            // place_id is null like every other DEFERRED result: a caller that
            // forgot to skip the write must not be handed an ID to persist.
            default => ['status' => self::DEFERRED, 'place_id' => null],
        };
    }

    private function request(string $fieldMask): PendingRequest
    {
        return Http::asJson()
            ->timeout(15)
            ->retry(2, 500, throw: false)
            ->withHeaders([
                'X-Goog-Api-Key' => (string) $this->key,
                'X-Goog-FieldMask' => $fieldMask,
            ]);
    }
}

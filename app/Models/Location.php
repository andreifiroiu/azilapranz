<?php

namespace App\Models;

use App\Support\GooglePlaces;
use App\Support\LegacyHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    /** Listed everywhere. The legacy site's only "live" value. */
    public const STATUS_ACTIVE = 'active';

    /**
     * Shut for good: out of every listing, but the page stays up at its
     * indexed URL and says so. Written only by azp:places:sync-status.
     */
    public const STATUS_CLOSED = 'closed';

    /** Deliberately gone — a 410. Only ever set by a human. */
    public const STATUS_SUSPENDED = 'suspended';

    /** Legacy value: trading, but running no lunch offer. Page stays up. */
    public const STATUS_NO_OFFER = 'nooffer';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'register_date' => 'datetime',
            'place_id_checked_at' => 'datetime',
            'place_id_status_changed_at' => 'datetime',
            'place_id_flag_reported_at' => 'datetime',
            'auto_closed_at' => 'datetime',
            'menu_price' => 'decimal:2',
            'rating' => 'decimal:2',
        ];
    }

    /**
     * Venues shown in listings — the legacy listing query filtered on this.
     *
     * Matching one exact value is what keeps a closed venue out of every
     * listing, the related-venues block and the RSS feed without any of them
     * having to know that `closed` exists.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Venues whose detail page may return 200.
     *
     * Excluding only `suspended` is what keeps a closed venue's page — and its
     * sitemap entry — alive at the URL Google indexed.
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', '!=', self::STATUS_SUSPENDED);
    }

    public function scopeInCity(Builder $query, string $citySlug): void
    {
        $query->where('city_slug', $citySlug);
    }

    /** Canonical path, e.g. /timisoara/restaurant-al-duomo-560.html */
    public function getPathAttribute(): string
    {
        return '/'.$this->city_slug.'/'.$this->slug.'.html';
    }

    // No getUrlAttribute() here on purpose: `url` is a real column holding the
    // venue's own website, and an accessor of that name would shadow it.
    // Use url($location->path) for the page's own address.

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Why the venue is closed, in the reader's words.
     *
     * Reads `place_id_status` rather than `status` because the two closures
     * mean different things to someone standing outside the door: a business
     * Google marks shut, versus a place that has gone from Google entirely and
     * may simply have moved.
     *
     * @return array{0: string, 1: string}|null [heading, explanation]
     */
    public function closureNotice(): ?array
    {
        if (! $this->isClosed()) {
            return null;
        }

        return match ($this->place_id_status) {
            GooglePlaces::CLOSED_PERMANENTLY => [
                'Local închis definitiv',
                'Google indică faptul că acest local nu mai funcționează.',
            ],
            GooglePlaces::NOT_FOUND => [
                'Local închis sau relocat',
                'Nu l-am mai găsit pe Google. Este posibil să se fi mutat la altă adresă.',
            ],
            // Everything else — including `resolved` after a re-search, which
            // says nothing about trading. The strongest claim must never be
            // the fallback: this text names a real business on a public page,
            // and a status we cannot interpret is not evidence for it.
            default => [
                'Local închis',
                'Informațiile despre acest local nu mai sunt actuale.',
            ],
        };
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Legacy `type` / `specific` / `attributes` are comma-separated multi-value
     * strings. Split, trim and drop the "Other" marker the old admin inserted.
     */
    protected function splitList(?string $value): array
    {
        if (blank($value)) {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($v) => trim($v))
            ->reject(fn ($v) => $v === '' || strcasecmp($v, 'other') === 0)
            ->values()
            ->all();
    }

    public function getTypeListAttribute(): array
    {
        return $this->splitList($this->type);
    }

    public function getSpecificListAttribute(): array
    {
        return $this->splitList($this->specific);
    }

    public function getServiceListAttribute(): array
    {
        return $this->splitList($this->services);
    }

    /**
     * Average rating out of 5, or null when nobody has voted.
     *
     * The legacy stored `rating` as a running SUM of scores, not an average.
     */
    public function getAverageRatingAttribute(): ?float
    {
        if ($this->rating_votes < 1) {
            return null;
        }

        return round((float) $this->rating / $this->rating_votes, 1);
    }

    /** Legacy CKEditor markup, rebuilt from a safe allowlist. */
    public function getDescriptionHtmlAttribute(): string
    {
        return LegacyHtml::clean($this->description);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/locations/'.$this->logo) : null;
    }

    /**
     * Whether the page shows Google's live place widget for this venue.
     *
     * When it does, the legacy `rating` is suppressed rather than displayed
     * beside Google's — two ratings for one restaurant is worse than one.
     * The aggregateRating in the page's JSON-LD follows the same condition,
     * because Google requires structured ratings to be visible on the page.
     */
    public function showsGooglePlace(): bool
    {
        // Never on a closed venue. The widget renders Google's own live hours
        // and photos, which would sit directly under our notice saying the
        // place has shut — and if a re-search ever matched a neighbouring
        // business, it would be that business's details on this venue's page.
        return GooglePlaces::uiKitEnabled()
            && filled($this->place_id)
            && ! $this->isClosed();
    }

    public function hasCoordinates(): bool
    {
        return is_numeric($this->latitude)
            && is_numeric($this->longitude)
            && (float) $this->latitude !== 0.0
            && (float) $this->longitude !== 0.0;
    }
}

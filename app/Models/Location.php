<?php

namespace App\Models;

use App\Support\GooglePlaces;
use App\Support\LegacyHtml;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'register_date' => 'datetime',
            'place_id_checked_at' => 'datetime',
            'menu_price' => 'decimal:2',
            'rating' => 'decimal:2',
        ];
    }

    /** Venues shown in listings — the legacy listing query filtered on this. */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /** Venues whose detail page may return 200. */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', '!=', 'suspended');
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

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
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
        return GooglePlaces::uiKitEnabled() && filled($this->place_id);
    }

    public function hasCoordinates(): bool
    {
        return is_numeric($this->latitude)
            && is_numeric($this->longitude)
            && (float) $this->latitude !== 0.0
            && (float) $this->longitude !== 0.0;
    }
}

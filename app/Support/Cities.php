<?php

namespace App\Support;

class Cities
{
    /** @return array<string,string> slug => display name */
    public static function all(): array
    {
        return config('azp.cities');
    }

    /** @return string[] */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    /** Regex alternation for Route::pattern('city', ...). */
    public static function routePattern(): string
    {
        return implode('|', array_map('preg_quote', self::slugs()));
    }

    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::all());
    }

    /**
     * Display name for a slug.
     *
     * Note this returns "Cluj Napoca", where the legacy site rendered
     * "Cluj-napoca" — it derived the display name with ucwords() on the slug,
     * which also left its city listing empty. See CityController.
     */
    public static function name(string $slug): string
    {
        return self::all()[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }

    public static function defaultSlug(): string
    {
        return config('azp.default_city');
    }
}

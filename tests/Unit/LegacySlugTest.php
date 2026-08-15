<?php

namespace Tests\Unit;

use App\Support\LegacySlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the port of the legacy URL::encodeUrlTerm().
 *
 * Expected values were produced by running the original function
 * (azilapranz.ro/lib/url.class.php) over the same inputs — not by reading the
 * code. If one of these changes, indexed URLs move.
 */
class LegacySlugTest extends TestCase
{
    #[DataProvider('slugs')]
    public function test_it_matches_the_legacy_output(string $input, string $expected): void
    {
        $this->assertSame($expected, LegacySlug::make($input));
    }

    public static function slugs(): array
    {
        return [
            'plain' => ['Al Duomo', 'al-duomo'],
            'lowercases' => ['CASA BUNICII', 'casa-bunicii'],
            'commas become dashes' => ['restaurant,pizzerie', 'restaurant-pizzerie'],
            'multi-word city' => ['Cluj Napoca', 'cluj-napoca'],
            'punctuation' => ["O'Neill's Pub", 'o-neill-s-pub'],
            'ampersand' => ['A & B', 'a-b'],
            'parentheses' => ['Bistro (Centru)', 'bistro-centru'],
            'trims dashes' => ['   spaced   out   ', 'spaced-out'],
            'all punctuation' => ['!!!', ''],
            'empty' => ['', ''],
            'only dashes' => ['---', ''],

            // Diacritics the legacy explicitly folded.
            'folds e' => ['Café', 'cafe'],
            'folds o' => ['Napóli', 'napoli'],
            // The folding table covers ó ö ő but not ô, which hits the catch-all.
            'unlisted circumflex becomes a dash' => ['Napôli', 'nap-li'],
            'folds u' => ['Müller', 'muller'],

            // Romanian diacritics were NOT in the legacy folding table; they fell
            // through to the catch-all regex and each became a dash.
            'romanian diacritics become dashes' => ['Piaţa Traian', 'pia-a-traian'],

            // Single-pass dash collapse: str_replace does not re-scan what it
            // wrote, so long runs are only partially collapsed.
            'collapses three dashes' => ['a---b', 'a-b'],
            'collapses four dashes' => ['a----b', 'a-b'],
            'five dashes survive as two' => ['a-----b', 'a--b'],

            // HTML entities are stripped before anything else.
            'strips entities' => ['Caf&eacute; Central', 'caf-central'],
        ];
    }

    public function test_it_builds_a_venue_slug_from_type_name_and_id(): void
    {
        $this->assertSame(
            'restaurant-al-duomo-560',
            LegacySlug::forLocation('restaurant', 'Al Duomo', 560)
        );
    }

    public function test_it_widens_the_slug_for_multi_value_types(): void
    {
        $this->assertSame(
            'restaurant-pizzerie-catering-al-pacino-36',
            LegacySlug::forLocation('restaurant,pizzerie,catering', 'Al Pacino', 36)
        );
    }

    public function test_a_null_type_leaves_no_leading_dash(): void
    {
        $this->assertSame('queen-80', LegacySlug::forLocation(null, 'Queen', 80));
    }
}

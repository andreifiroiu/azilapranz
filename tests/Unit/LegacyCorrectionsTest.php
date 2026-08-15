<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the hand-retyped text in database/legacy-corrections.php.
 *
 * These strings exist because a Latin-1 round-trip in the legacy admin replaced
 * every ă/ș/ț with a literal "?". If a correction were to reintroduce the
 * corruption — or lose its diacritics — the import would silently write bad
 * text back over the repair.
 */
class LegacyCorrectionsTest extends TestCase
{
    /** @return array<int, array<string, string>> */
    private function corrections(): array
    {
        return require __DIR__.'/../../database/legacy-corrections.php';
    }

    public function test_no_correction_still_contains_a_destroyed_diacritic(): void
    {
        foreach ($this->corrections() as $id => $columns) {
            foreach ($columns as $column => $value) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\p{L}\?\p{L}/u',
                    $value,
                    "locations.{$column} #{$id} still contains a corrupted character"
                );
            }
        }
    }

    public function test_every_correction_carries_romanian_diacritics(): void
    {
        foreach ($this->corrections() as $id => $columns) {
            foreach ($columns as $column => $value) {
                $this->assertMatchesRegularExpression(
                    '/[ăâîșțĂÂÎȘȚ]/u',
                    $value,
                    "locations.{$column} #{$id} has no diacritics, so it repairs nothing"
                );
            }
        }
    }

    public function test_corrections_are_valid_utf8(): void
    {
        foreach ($this->corrections() as $id => $columns) {
            foreach ($columns as $column => $value) {
                $this->assertTrue(
                    mb_check_encoding($value, 'UTF-8'),
                    "locations.{$column} #{$id} is not valid UTF-8"
                );
            }
        }
    }

    public function test_the_expected_fields_are_covered(): void
    {
        $corrections = $this->corrections();

        $this->assertSame([57, 79, 94], array_keys($corrections));
        $this->assertArrayHasKey('description', $corrections[57]);
        $this->assertArrayHasKey('description', $corrections[79]);
        $this->assertSame('Str. Piața Victoriei, Nr. 2', $corrections[94]['address']);
    }
}

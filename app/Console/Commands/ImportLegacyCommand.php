<?php

namespace App\Console\Commands;

use App\Support\LegacySlug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportLegacyCommand extends Command
{
    protected $signature = 'azp:import-legacy
                            {--skip-images : Do not copy venue logos from the legacy site}';

    protected $description = 'Import locations, areas and CMS pages from the legacy bf_azilapranz_old database';

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        try {
            $legacy->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the legacy database: '.$e->getMessage());
            $this->line('Check LEGACY_DB_* in your .env.');

            return self::FAILURE;
        }

        // All three tables are replaced together or not at all. Note this uses
        // DELETE rather than TRUNCATE deliberately: TRUNCATE is DDL and forces
        // an implicit commit in MySQL, so a truncate inside a transaction
        // cannot be rolled back and a mid-import failure would leave the site
        // serving a partial directory — 200s everywhere, hundreds of indexed
        // venue URLs quietly gone. Row counts here are in the hundreds, so the
        // cost of DELETE over TRUNCATE is irrelevant.
        try {
            DB::transaction(function () use ($legacy) {
                $this->importLocations($legacy);
                $this->applyCorrections();
                $this->importAreaGroups($legacy);
                $this->importPages($legacy);
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('Import failed and was rolled back: '.$e->getMessage());
            $this->line('The previous data is intact.');

            return self::FAILURE;
        }

        if (! $this->option('skip-images')) {
            if (! $this->importLogos()) {
                $this->newLine();
                $this->error('Data imported, but the logo copy failed. Re-run once the source is reachable.');

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Legacy import complete.');

        return self::SUCCESS;
    }

    private function importLocations($legacy): void
    {
        $rows = $legacy->table('locations')->orderBy('id')->get();
        $now = now();
        $records = [];

        foreach ($rows as $r) {
            $citySlug = LegacySlug::make($r->city);

            $records[] = [
                'id' => (int) $r->id,
                'name' => $r->name,
                'slug' => LegacySlug::forLocation($r->type, $r->name, (int) $r->id),
                'city' => $r->city,
                'city_slug' => $citySlug,
                'area' => $r->area,
                'address' => $r->address,
                'phone' => $r->phone,
                'email' => $r->email,
                'url' => $r->url,
                'facebook' => $r->facebook ?: null,
                'twitter' => $r->twitter ?: null,
                'order_url' => $r->order_url ?: null,
                'description' => $r->description,
                'type' => $r->type,
                'specific' => $r->specific,
                'services' => $r->attributes,
                'logo' => $r->logo ?: null,
                'latitude' => $r->latitude ?: null,
                'longitude' => $r->longitude ?: null,
                'hours' => $r->hours ?: null,
                'menu_price' => $r->menu_price ?: 0,
                'capacity' => (int) $r->capacity,
                'rating' => $r->rating ?: 0,
                'rating_votes' => (int) $r->rating_votes,
                'stars' => $r->stars !== null ? (int) $r->stars : null,
                'views' => (int) $r->views,
                // Legacy left this empty string on some rows; normalise so the
                // published/active scopes behave predictably.
                'status' => $r->status ?: 'standby',
                'register_date' => $this->date($r->register_date),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Resolved Google place IDs are not in the legacy data and each one cost
        // a billed Text Search call, so carry them across the replace rather
        // than making a re-import silently require a full re-backfill.
        $places = DB::table('locations')
            ->whereNotNull('place_id')
            ->get(['id', 'place_id', 'place_id_status', 'place_id_checked_at'])
            ->keyBy('id');

        DB::table('locations')->delete();
        foreach (array_chunk($records, 200) as $chunk) {
            DB::table('locations')->insert($chunk);
        }

        foreach ($places as $id => $place) {
            DB::table('locations')->where('id', $id)->update([
                'place_id' => $place->place_id,
                'place_id_status' => $place->place_id_status,
                'place_id_checked_at' => $place->place_id_checked_at,
            ]);
        }

        if ($places->isNotEmpty()) {
            $this->line(sprintf('place ids   : %d preserved', $places->count()));
        }

        $active = collect($records)->where('status', 'active')->count();
        $this->line(sprintf('locations   : %d imported (%d active)', count($records), $active));

        $this->warnOnDuplicateSlugs($records);
    }

    /**
     * Two venues resolving to the same canonical path would make one of them
     * unreachable, so surface it rather than letting it pass silently.
     */
    private function warnOnDuplicateSlugs(array $records): void
    {
        $dupes = collect($records)
            ->groupBy(fn ($r) => $r['city_slug'].'/'.$r['slug'])
            ->filter(fn ($g) => $g->count() > 1);

        if ($dupes->isEmpty()) {
            return;
        }

        $this->warn(sprintf('  %d duplicate canonical path(s) detected:', $dupes->count()));
        foreach ($dupes as $path => $group) {
            $this->warn('    /'.$path.' <- ids '.$group->pluck('id')->join(', '));
        }
    }

    /**
     * Restore the Romanian characters a Latin-1 round-trip destroyed in the
     * legacy data. See database/legacy-corrections.php for the reasoning.
     */
    private function applyCorrections(): void
    {
        $corrections = require database_path('legacy-corrections.php');

        $applied = 0;
        $skipped = 0;

        foreach ($corrections as $id => $columns) {
            $row = DB::table('locations')->find($id);

            if (! $row) {
                $this->warn("  correction skipped: location {$id} not found");
                $skipped++;

                continue;
            }

            $updates = [];

            foreach ($columns as $column => $corrected) {
                // Only touch values that still show the corruption, so a value
                // fixed upstream is never clobbered by this list.
                if (! $this->isCorrupted((string) $row->{$column})) {
                    $this->warn("  correction skipped: locations.{$column} #{$id} is no longer corrupted");
                    $skipped++;

                    continue;
                }

                $updates[$column] = $corrected;
            }

            if ($updates) {
                DB::table('locations')->where('id', $id)->update($updates);
                $applied += count($updates);
            }
        }

        $this->line(sprintf(
            'corrections : %d field(s) repaired%s',
            $applied,
            $skipped ? ", {$skipped} skipped" : ''
        ));
    }

    /**
     * Whether a value still shows the Latin-1 damage a correction repairs.
     *
     * Any "?" counts, not just one between two letters: the destroyed
     * characters are ă/ș/ț, and word-initial "Ș" is common in Romanian
     * ("Str. ?tefan cel Mare"), so requiring a letter on both sides reported
     * corrupted rows as clean and silently discarded their correction. None of
     * the fields in the corrections map legitimately contains a question mark,
     * which is asserted by LegacyCorrectionsTest.
     */
    private function isCorrupted(string $value): bool
    {
        return str_contains($value, '?');
    }

    private function importAreaGroups($legacy): void
    {
        $rows = $legacy->table('area_groups')->orderBy('id')->get();
        $now = now();

        $records = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'area' => $r->area,
            'city' => $r->city,
            'city_slug' => LegacySlug::make($r->city),
            'generalarea' => $r->generalarea,
            'equivalents' => $r->equivalents,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('area_groups')->delete();
        if ($records) {
            DB::table('area_groups')->insert($records);
        }

        $this->line(sprintf('area_groups : %d imported', count($records)));
    }

    private function importPages($legacy): void
    {
        $rows = $legacy->table('pages')->orderBy('id')->get();
        $now = now();

        $records = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'title' => $r->title,
            'name' => $r->name,
            'content' => $r->content,
            'meta_description' => $r->meta_description ?: null,
            'meta_keywords' => $r->meta_keywords ?: null,
            'published' => (bool) $r->published,
            'is_sitemap' => (bool) $r->is_sitemap,
            'parent_page' => (int) $r->parent_page,
            'position' => (int) $r->position,
            'language' => $r->language,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('pages')->delete();
        if ($records) {
            DB::table('pages')->insert($records);
        }

        $this->line(sprintf('pages       : %d imported', count($records)));
    }

    /** @return bool false when the copy did not complete */
    private function importLogos(): bool
    {
        // Read through config, not env(): once a deployment runs config:cache
        // the .env file is never loaded and env() returns null, which would
        // report the path as unset while it sits in .env perfectly readable.
        $root = rtrim((string) config('azp.legacy_site_path'), '/');

        if ($root === '' || ! is_dir($root)) {
            $this->error('logos       : FAILED — azp.legacy_site_path is not set to a readable directory');

            return false;
        }

        $source = $root.'/userfiles/locations';

        if (! is_dir($source)) {
            $this->error('logos       : FAILED — '.$source.' not found');

            return false;
        }

        $target = storage_path('app/public/locations');
        File::ensureDirectoryExists($target);

        // locations.logo holds a path relative to userfiles/locations/, which is
        // sometimes bare ("pub-jarvis-timisoara.png") and sometimes nested under
        // a city directory ("timisoara/gradina-banateana.png"). Copy the tree
        // whole so both forms resolve.
        if (! File::copyDirectory($source, $target)) {
            $this->error('logos       : FAILED — copy from '.$source.' did not complete');

            return false;
        }

        // Count the source, not the destination: copyDirectory never cleans the
        // target, so counting it would report files left by an earlier run as
        // work this run did.
        $copied = count(File::allFiles($source));

        $this->line(sprintf('logos       : %d copied to storage/app/public/locations', $copied));

        return $this->reportMissingLogos($target);
    }

    /**
     * A logo row pointing at a file that no longer exists renders a broken image,
     * so blank those out at import time rather than at request time.
     */
    private function reportMissingLogos(string $target): bool
    {
        $referenced = DB::table('locations')
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->pluck('logo', 'id');

        $missing = $referenced->reject(fn ($logo) => is_file($target.'/'.$logo));

        if ($missing->isEmpty()) {
            return true;
        }

        // If nearly every reference is missing, the copy silently did nothing
        // rather than the data being stale — and blanking the column would wipe
        // every logo on the site while reporting it as routine cleanup.
        if ($referenced->isNotEmpty() && $missing->count() > $referenced->count() * 0.2) {
            $this->error(sprintf(
                '  %d of %d logo references point at missing files — refusing to clear them.',
                $missing->count(),
                $referenced->count()
            ));
            $this->line('  That ratio means the copy failed, not that the data is stale.');

            return false;
        }

        DB::table('locations')->whereIn('id', $missing->keys())->update(['logo' => null]);

        $this->warn(sprintf(
            '  %d logo reference(s) pointed at missing files and were cleared.',
            $missing->count()
        ));

        return true;
    }

    private function date(?string $value): ?string
    {
        if (blank($value) || str_starts_with($value, '0000')) {
            return null;
        }

        return $value;
    }
}

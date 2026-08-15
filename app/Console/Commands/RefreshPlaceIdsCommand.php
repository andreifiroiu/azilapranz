<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Console\Command;

/**
 * Monthly liveness check.
 *
 * A place ID that stops resolving is the clearest signal we get that a venue
 * has closed or moved. It is a signal, not a verdict: `status` drives routing
 * and the deliberate 410s in LocationController, so a venue is flagged for a
 * human here and never suspended automatically.
 */
class RefreshPlaceIdsCommand extends Command
{
    protected $signature = 'azp:places:refresh
                            {--older-than=30 : Only re-check IDs last checked this many days ago}
                            {--limit=0 : Stop after this many venues}';

    protected $description = 'Re-check stored place IDs and flag venues whose place has disappeared';

    public function handle(GooglePlaces $places): int
    {
        if (! $places->configured()) {
            $this->error('GOOGLE_PLACES_API_KEY is not set.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays((int) $this->option('older-than'));

        $query = Location::query()
            ->whereNotNull('place_id')
            ->where(fn ($q) => $q
                ->whereNull('place_id_checked_at')
                ->orWhere('place_id_checked_at', '<=', $cutoff))
            ->orderBy('place_id_checked_at');

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $venues = $query->get();

        if ($venues->isEmpty()) {
            $this->info('No place IDs are due for a re-check.');

            return self::SUCCESS;
        }

        $flagged = [];
        $moved = 0;
        $deferred = 0;

        foreach ($venues as $venue) {
            $result = $places->refresh($venue->place_id);

            if ($result['status'] === GooglePlaces::DEFERRED) {
                // Not checked at all. Leave `place_id_checked_at` alone so the
                // next run retries instead of treating this venue as done.
                $deferred++;

                continue;
            }

            if ($result['status'] === GooglePlaces::OK && $result['place_id'] !== $venue->place_id) {
                $moved++;
            }

            if ($result['status'] !== GooglePlaces::OK) {
                $flagged[] = $venue;
            }

            $venue->forceFill([
                // A failed check clears the ID; the status records why, so the
                // next backfill run can try to re-resolve it from a search.
                'place_id' => $result['place_id'],
                'place_id_status' => $result['status'],
                'place_id_checked_at' => now(),
            ])->save();
        }

        $this->line(sprintf('checked : %d', $venues->count() - $deferred));
        $this->line(sprintf('moved   : %d (place ID replaced by Google)', $moved));

        if ($deferred) {
            $this->warn(sprintf('deferred: %d (API errors — left untouched, re-run to retry)', $deferred));
        }

        if ($flagged) {
            $this->newLine();
            $this->warn(sprintf('%d venue(s) need a human look — their place no longer resolves:', count($flagged)));

            $this->table(
                ['id', 'name', 'city', 'status', 'page'],
                collect($flagged)->map(fn (Location $l) => [
                    $l->id, $l->name, $l->city, $l->place_id_status, $l->path,
                ])->all(),
            );

            $this->line('Status was left untouched. Suspend or correct these by hand.');
        }

        return self::SUCCESS;
    }
}

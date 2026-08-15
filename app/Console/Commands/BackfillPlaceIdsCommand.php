<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Console\Command;

class BackfillPlaceIdsCommand extends Command
{
    protected $signature = 'azp:places:backfill
                            {--city= : Restrict to one city slug}
                            {--limit=0 : Stop after this many venues}
                            {--force : Re-resolve venues that already have a place ID}
                            {--dry-run : Resolve and report without writing}';

    protected $description = 'Resolve each venue to a Google place ID (the ID only — no venue data is stored)';

    public function handle(GooglePlaces $places): int
    {
        if (! $places->configured()) {
            $this->error('GOOGLE_PLACES_API_KEY is not set.');

            return self::FAILURE;
        }

        $query = Location::query()->active();

        if ($city = $this->option('city')) {
            $query->inCity($city);
        }

        if (! $this->option('force')) {
            $query->whereNull('place_id');
        }

        if ($limit = (int) $this->option('limit')) {
            $query->limit($limit);
        }

        $venues = $query->orderBy('id')->get();

        if ($venues->isEmpty()) {
            $this->info('Nothing to resolve.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = [GooglePlaces::OK => 0, GooglePlaces::UNMATCHED => 0, GooglePlaces::DEFERRED => 0];

        $bar = $this->output->createProgressBar($venues->count());
        $bar->start();

        foreach ($venues as $venue) {
            $result = $places->search($venue);
            $counts[$result['status']]++;

            if (! $dryRun && $result['status'] !== GooglePlaces::DEFERRED) {
                // Only the ID and our own bookkeeping — see GooglePlaces for
                // why nothing else from the response may be persisted.
                $venue->forceFill([
                    'place_id' => $result['place_id'],
                    'place_id_status' => $result['status'],
                    'place_id_checked_at' => now(),
                ])->save();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line(sprintf('matched   : %d', $counts[GooglePlaces::OK]));
        $this->line(sprintf('unmatched : %d', $counts[GooglePlaces::UNMATCHED]));

        if ($deferred = $counts[GooglePlaces::DEFERRED]) {
            $this->warn(sprintf('deferred  : %d (API errors — left untouched, re-run to retry)', $deferred));
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}

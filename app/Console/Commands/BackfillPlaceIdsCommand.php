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
                            {--force : Re-resolve every venue, including ones already matched}
                            {--retry-unmatched : Also retry venues a previous search found nothing for}
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

            // Text Search is billed per call. A venue a previous run already
            // searched and found nothing for would otherwise be re-queried on
            // every run forever, because UNMATCHED also stores a null place_id.
            if (! $this->option('retry-unmatched')) {
                $query->where(fn ($q) => $q
                    ->whereNull('place_id_status')
                    ->orWhereNotIn('place_id_status', [GooglePlaces::UNMATCHED]));
            }
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

            if (! $dryRun && ! GooglePlaces::isDeferred($result)) {
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

        // A run of real Romanian restaurants that matches nothing at all is a
        // broken integration, not a fact about the venues — a changed response
        // shape, an ignored field mask, a 200 carrying an error envelope. Left
        // unguarded it would write "unmatched" over hundreds of rows and exit 0.
        $attempted = $venues->count() - $counts[GooglePlaces::DEFERRED];

        if ($attempted >= 20 && $counts[GooglePlaces::OK] === 0) {
            $this->newLine();
            $this->error(sprintf('All %d venues came back unmatched — treating that as a bug, not a result.', $attempted));
            $this->line('Check the API key, the field mask and the Text Search response shape.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

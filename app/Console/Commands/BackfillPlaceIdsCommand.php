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

        // published(), not active(): every venue with a live page is worth an
        // ID. Narrowing this to `active` stranded closed venues — refresh()
        // clears place_id on a vanished place, which drops the venue out of
        // refresh's pool, and if backfill will not search for it either then
        // nothing can ever re-resolve it and sync-status can never reopen it.
        $query = Location::query()->published();

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
        $counts = [GooglePlaces::RESOLVED => 0, GooglePlaces::UNMATCHED => 0, GooglePlaces::DEFERRED => 0];
        $unmatched = [];

        $bar = $this->output->createProgressBar($venues->count());
        $bar->start();

        // Collected, not written, until the sanity guard below has run. Writing
        // inside the loop meant the guard could only change the exit code —
        // hundreds of rows were already marked `unmatched`, and `unmatched` is
        // excluded from every future run.
        $pending = [];

        foreach ($venues as $venue) {
            $result = $places->search($venue);
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;

            if ($result['status'] === GooglePlaces::UNMATCHED) {
                $unmatched[] = $venue;
            }

            if (! GooglePlaces::isDeferred($result)) {
                $pending[] = [$venue, $result];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $attempted = $venues->count() - $counts[GooglePlaces::DEFERRED];

        // A run of real Romanian restaurants that matches nothing at all is a
        // broken integration, not a fact about the venues — a changed response
        // shape, an ignored field mask, a 200 carrying an error envelope.
        // --retry-unmatched is exempt: that flag deliberately selects venues a
        // previous run already failed to match, so zero matches is the norm.
        if ($attempted >= 5
            && $counts[GooglePlaces::RESOLVED] === 0
            && ! $this->option('retry-unmatched')) {
            $this->error(sprintf('All %d venues came back unmatched — treating that as a bug, not a result.', $attempted));
            $this->line('Check the API key, the field mask and the Text Search response shape.');
            $this->warn('Nothing was written.');

            return self::FAILURE;
        }

        if (! $dryRun) {
            foreach ($pending as [$venue, $result]) {
                // Only the ID and our own bookkeeping — see GooglePlaces for
                // why nothing else from the response may be persisted.
                $venue->forceFill([
                    'place_id' => $result['place_id'],
                    'place_id_status' => $result['status'],
                    // Null on a match, so the next refresh treats the venue as
                    // due and settles its trading status. A search cannot see
                    // businessStatus, so nothing here has been "checked" yet.
                    'place_id_checked_at' => $result['status'] === GooglePlaces::RESOLVED
                        ? null
                        : now(),
                ])->save();
            }
        }

        $this->line(sprintf('matched   : %d', $counts[GooglePlaces::RESOLVED]));
        $this->line(sprintf('unmatched : %d', $counts[GooglePlaces::UNMATCHED]));

        if ($deferred = $counts[GooglePlaces::DEFERRED]) {
            $this->warn(sprintf('deferred  : %d (API errors — left untouched, re-run to retry)', $deferred));
        }

        if ($unmatched) {
            $this->newLine();
            $this->warn(sprintf('%d venue(s) Google could not match:', count($unmatched)));

            // Address and city are what the search query was built from, so
            // they are what to look at when a match fails — usually a legacy
            // address that no longer exists or a venue renamed years ago.
            $this->table(
                ['id', 'name', 'city', 'address'],
                collect($unmatched)->map(fn (Location $l) => [
                    $l->id, $l->name, $l->city, $l->address,
                ])->all(),
            );

            $this->line('These are skipped on later runs. Fix the address or name,');
            $this->line('then re-run with --retry-unmatched.');
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}

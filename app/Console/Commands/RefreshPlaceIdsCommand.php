<?php

namespace App\Console\Commands;

use App\Mail\VenueReviewReport;
use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

            if (GooglePlaces::isDeferred($result)) {
                // Not checked at all. Leave `place_id_checked_at` alone so the
                // next run retries instead of treating this venue as done.
                $deferred++;

                continue;
            }

            if ($result['place_id'] && $result['place_id'] !== $venue->place_id) {
                $moved++;
            }

            $changed = $result['status'] !== $venue->place_id_status;

            // News is anything under review that has not been *successfully
            // reported yet* — not merely anything that changed this run.
            // Inferring it from the diff made the alert one-shot: a failed send
            // destroyed the only evidence the venue was new, and sync-status
            // would later close it on a flag nobody had ever seen.
            $unreported = $venue->place_id_flag_reported_at === null
                || ($venue->place_id_status_changed_at
                    && $venue->place_id_flag_reported_at->lt($venue->place_id_status_changed_at));

            if (($changed || $unreported) && in_array($result['status'], GooglePlaces::NEEDS_REVIEW, true)) {
                $flagged[] = $venue;
            }

            $venue->forceFill([
                // NOT_FOUND and INVALID return null and so clear the ID, which
                // lets the next backfill re-resolve it from a search. A closed
                // venue keeps its ID: the place still exists, the business does
                // not, and searching again would only find it a second time.
                // Keep the outgoing ID when a failed check clears it: a place
                // ID costs a billed search to regenerate and a re-search can
                // match a different venue, so it is worth one nullable column.
                'previous_place_id' => $result['place_id'] === null && $venue->place_id
                    ? $venue->place_id
                    : $venue->previous_place_id,
                'place_id' => $result['place_id'],
                'place_id_status' => $result['status'],
                'place_id_checked_at' => now(),
                // Stamped only on a real change, because azp:places:sync-status
                // uses it as the age of the flag when applying its grace period.
                'place_id_status_changed_at' => $changed
                    ? now()
                    : $venue->place_id_status_changed_at,
            ])->save();
        }

        $this->line(sprintf('checked : %d', $venues->count() - $deferred));
        $this->line(sprintf('moved   : %d (place ID replaced by Google)', $moved));

        if ($deferred) {
            $this->warn(sprintf('deferred: %d (API errors — left untouched, re-run to retry)', $deferred));
        }

        if ($flagged) {
            // Ordered so permanently-closed leads: it is both the strongest
            // signal and the one this check exists to surface.
            $flagged = collect($flagged)
                ->sortBy(fn (Location $l) => array_search($l->place_id_status, GooglePlaces::NEEDS_REVIEW, true))
                ->values();

            $this->newLine();
            $this->warn(sprintf('%d venue(s) newly need a human look:', $flagged->count()));

            $this->table(
                ['id', 'name', 'city', 'place_id_status', 'page'],
                $flagged->map(fn (Location $l) => [
                    $l->id, $l->name, $l->city, $l->place_id_status, $l->path,
                ])->all(),
            );

            $this->line('The venue `status` column was left untouched. Suspend or correct these by hand.');

            // The only context this command runs in is the scheduler, whose
            // stdout goes nowhere. Without this the escalation above — the
            // entire point of the monthly check — is written and discarded.
            Log::warning('Venues newly flagged by the Google place check', [
                'count' => $flagged->count(),
                'venues' => $flagged->map(fn (Location $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'city' => $l->city,
                    'place_id_status' => $l->place_id_status,
                    'path' => $l->path,
                ])->all(),
            ]);

            $this->email($flagged);
        }

        return self::SUCCESS;
    }

    /**
     * Report the flagged venues, and record it only if that succeeded.
     *
     * @param  Collection<int, Location>  $flagged
     */
    private function email(Collection $flagged): void
    {
        $to = config('azp.alert_email');

        if (blank($to)) {
            // Log, not line(): under the scheduler stdout goes nowhere, and an
            // empty AZP_ALERT_EMAIL is the default state of a fresh deployment.
            // The Log::warning above is the report in this configuration, so
            // the venues count as reported and will not be re-flagged forever.
            Log::warning('Venue review alert not emailed: azp.alert_email is empty', [
                'flagged' => $flagged->count(),
            ]);
            $this->warn('AZP_ALERT_EMAIL is not set — logged only, no email sent.');
            $this->markReported($flagged);

            return;
        }

        try {
            Mail::to($to)->send(new VenueReviewReport($flagged));
            $this->info(sprintf('Alert sent to %s.', $to));
            $this->markReported($flagged);
        } catch (\Throwable $e) {
            // A dead mailer must not fail the run: the checks already landed in
            // the database and the log, and the next run would redo the billed
            // calls for nothing. Crucially the venues stay unreported, so
            // tomorrow's run raises them again instead of losing them.
            Log::error('Could not send the venue review alert', [
                'error' => $e->getMessage(),
                'flagged' => $flagged->count(),
            ]);
            $this->error('Could not send the alert email: '.$e->getMessage());
            $this->warn('These venues stay flagged and will be reported again next run.');
        }
    }

    /** @param  Collection<int, Location>  $flagged */
    private function markReported(Collection $flagged): void
    {
        // pluck, not modelKeys(): $flagged is a plain support Collection built
        // in the loop, not an Eloquent one.
        Location::whereKey($flagged->pluck('id')->all())
            ->update(['place_id_flag_reported_at' => now()]);
    }
}

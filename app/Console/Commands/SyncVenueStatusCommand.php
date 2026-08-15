<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Support\GooglePlaces;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Applies `place_id_status` to the `status` column.
 *
 * Closing a venue means `closed`, not `suspended`. The difference is the whole
 * point: scopeActive() only matches 'active', so a closed venue leaves every
 * listing, while scopePublished() only excludes 'suspended', so its page keeps
 * answering 200 at the URL Google indexed and says on the page that the place
 * has shut. Suspension — a deliberate 410 — stays a human decision.
 *
 * It is still built to be wrong safely rather than to be clever:
 *
 *  - only `closed_permanently` closes by default, because `not_found` also
 *    fires on relocations and Google's own database churn;
 *  - a flag must have held steady for --min-age days, so one bad day at Google
 *    cannot bury a trading restaurant;
 *  - every closure records the status it replaced, and a venue that reopens
 *    gets that exact status back;
 *  - venues a human set to any status have no such record and are never
 *    reopened by this command.
 */
class SyncVenueStatusCommand extends Command
{
    protected $signature = 'azp:places:sync-status
                            {--min-age=14 : Days a closure flag must have held before it is applied}
                            {--include-not-found : Also close venues whose place vanished from Google}
                            {--max=25 : Refuse to run if more than this many venues would be closed}
                            {--dry-run : Report what would change without writing}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Mark venues Google reports as permanently closed, and reopen ones that came back';

    private const CLOSED = Location::STATUS_CLOSED;

    public function handle(): int
    {
        // (int) 'fourteen' is 0, which would silently remove the grace period
        // and close flags stamped seconds ago; (int) 'abc' on --max would
        // silently refuse every closure. Both fail loudly instead.
        foreach (['min-age' => 1, 'max' => 0] as $option => $floor) {
            $value = $this->option($option);

            if (! is_numeric($value) || (int) $value < $floor) {
                $this->error(sprintf('--%s must be a number of at least %d.', $option, $floor));

                return self::FAILURE;
            }
        }

        $minAge = (int) $this->option('min-age');
        $cutoff = now()->subDays($minAge);

        $reasons = [GooglePlaces::CLOSED_PERMANENTLY];

        if ($this->option('include-not-found')) {
            $reasons[] = GooglePlaces::NOT_FOUND;
        }

        $toClose = Location::query()
            ->whereIn('place_id_status', $reasons)
            // Only statuses where "closed" is a meaningful downgrade. Backfill
            // now covers every published venue, which brought `standby` and
            // `uncontacted` into scope — venues nobody ever verified, whose
            // pages would start asserting a closure on the strength of a match
            // against a 2011 address.
            ->whereIn('status', [Location::STATUS_ACTIVE, Location::STATUS_NO_OFFER])
            // A venue still carrying our closure marker while not being closed
            // is one a human moved back by hand. Re-closing it would overwrite
            // that decision every night; `auto_closed_from` is nulled on a
            // legitimate restore, so this only ever catches human edits.
            ->whereNull('auto_closed_from')
            ->whereNotNull('place_id_status_changed_at')
            ->where('place_id_status_changed_at', '<=', $cutoff)
            // The flag must also still be *live*. Age alone was not a grace
            // period: DEFERRED deliberately freezes place_id_checked_at, so a
            // Google outage or an exhausted quota made every stale flag more
            // eligible with each passing day rather than less. A flag nobody
            // has been able to re-confirm inside the window is not settled.
            ->whereNotNull('place_id_checked_at')
            ->where('place_id_checked_at', '>=', $cutoff)
            // Never act on a closure that was never successfully reported —
            // otherwise a venue leaves the listings before anyone is told.
            ->whereNotNull('place_id_flag_reported_at')
            ->orderBy('id')
            ->get();

        $toRestore = Location::query()
            // Only ours: a status a human set has no auto_closed_from.
            ->whereNotNull('auto_closed_from')
            ->where('status', self::CLOSED)
            ->where('place_id_status', GooglePlaces::OK)
            ->orderBy('id')
            ->get();

        $this->reportStuck();

        if ($toClose->isEmpty() && $toRestore->isEmpty()) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        // Blast radius, on the closures only. Backfill already refuses to write
        // when every venue comes back negative, on the reasoning that a
        // mass-negative result is a bug rather than a fact; the command that
        // actually pulls pages out of the listings had no equivalent. One bad
        // fortnight at Google should not empty the directory in a --force run.
        //
        // Deliberately *not* a guard on the whole command: reopening is the
        // corrective direction, and blocking it because an unrelated mass
        // closure tripped the ceiling would strand trading venues out of every
        // listing for exactly as long as the problem took to notice.
        $ceiling = (int) $this->option('max');
        $overCeiling = $toClose->count() > $ceiling;

        // Reported before the ceiling decides anything, so --dry-run still
        // names the venues — otherwise its own advice to "inspect with
        // --dry-run" is unfollowable.
        $this->report($toClose, $toRestore, $minAge);

        if ($overCeiling) {
            $this->newLine();
            $this->error(sprintf(
                '%d venues would be closed, over the --max of %d — none were closed.',
                $toClose->count(),
                $ceiling,
            ));
            $this->line('That many at once usually means a bad check, not a bad month.');
            $this->line('Raise --max deliberately if the list above is real.');

            $toClose = $toClose->take(0);
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — nothing was written.');

            return $overCeiling ? self::FAILURE : self::SUCCESS;
        }

        if ($toClose->isEmpty() && $toRestore->isEmpty()) {
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Apply these changes?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        foreach ($toClose as $venue) {
            $venue->forceFill([
                'auto_closed_from' => $venue->status,
                'auto_closed_at' => now(),
                'status' => self::CLOSED,
            ])->save();
        }

        foreach ($toRestore as $venue) {
            $venue->forceFill([
                'status' => $venue->auto_closed_from,
                'auto_closed_from' => null,
                'auto_closed_at' => null,
            ])->save();
        }

        // These venues leave and rejoin the listings and the internal link
        // graph, so the change needs a durable record outside this terminal.
        Log::warning('Venue statuses changed by the Google place sync', [
            'closed' => $toClose->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'from' => $l->auto_closed_from])->all(),
            'reopened' => $toRestore->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name, 'to' => $l->status])->all(),
        ]);

        $this->newLine();
        $this->info(sprintf('%d closed, %d reopened.', $toClose->count(), $toRestore->count()));

        // A run that reopened what it could but refused the closures is not a
        // success, or a scheduled invocation would swallow the refusal.
        return $overCeiling ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Venues we closed that no code path can reopen.
     *
     * Reopening requires `ok`, which only a refresh returning OPERATIONAL can
     * produce. A venue whose place resolves without a `businessStatus` lands on
     * `resolved` instead — correctly, since absence is not a confirmation — and
     * then sits closed forever. That errs in the safe direction, but silently,
     * so it is surfaced here rather than left for someone to notice.
     */
    private function reportStuck(): void
    {
        $stuck = Location::query()
            ->where('status', self::CLOSED)
            ->whereNotNull('auto_closed_from')
            ->whereNotIn('place_id_status', array_merge(GooglePlaces::NEEDS_REVIEW, [GooglePlaces::OK]))
            ->orderBy('id')
            ->get();

        if ($stuck->isEmpty()) {
            return;
        }

        $this->warn(sprintf(
            '%d closed venue(s) can no longer reopen automatically — Google resolves the place but reports no trading status:',
            $stuck->count(),
        ));

        $this->table(
            ['id', 'name', 'city', 'place_id_status', 'would return to'],
            $stuck->map(fn (Location $l) => [
                $l->id, $l->name, $l->city, $l->place_id_status, $l->auto_closed_from,
            ])->all(),
        );

        $this->line('Check these by hand; clearing auto_closed_from and status reverses one.');
        $this->newLine();
    }

    /**
     * @param  Collection<int, Location>  $toClose
     * @param  Collection<int, Location>  $toRestore
     */
    private function report(Collection $toClose, Collection $toRestore, int $minAge): void
    {
        if ($toClose->isNotEmpty()) {
            $this->warn(sprintf(
                '%d venue(s) to mark closed — they leave the listings, their pages stay up:',
                $toClose->count(),
            ));

            // `checked` alongside `flagged`: an old flag reads as settled, but
            // it can also mean nobody has been able to re-confirm it. Showing
            // both lets the person at the prompt tell those apart.
            $this->table(
                ['id', 'name', 'city', 'status', 'reason', 'flagged', 'checked'],
                $toClose->map(fn (Location $l) => [
                    $l->id,
                    $l->name,
                    $l->city,
                    $l->status,
                    $l->place_id_status,
                    $l->place_id_status_changed_at?->diffForHumans(),
                    $l->place_id_checked_at?->diffForHumans(),
                ])->all(),
            );

            $this->line(sprintf('Flags younger than %d day(s) were left alone.', $minAge));
        }

        if ($toRestore->isNotEmpty()) {
            $this->newLine();
            $this->info(sprintf('%d venue(s) to reopen — Google shows them trading again:', $toRestore->count()));

            $this->table(
                ['id', 'name', 'city', 'restoring to'],
                $toRestore->map(fn (Location $l) => [
                    $l->id, $l->name, $l->city, $l->auto_closed_from,
                ])->all(),
            );
        }
    }
}

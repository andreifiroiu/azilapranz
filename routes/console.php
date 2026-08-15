<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Google Places upkeep.
 *
 * Both jobs no-op without an API key. skip() is silent by design and the
 * scheduler does not record skipped runs, so the key is checked here once and
 * logged — otherwise a deployment that ran config:cache before the key existed
 * would bake in a null and disable these forever with no signal at all.
 */
$placesConfigured = filled(config('azp.google.places_key'));

if (! $placesConfigured) {
    Log::debug('Google Places jobs are not scheduled: azp.google.places_key is empty.');
}

// An ID-only Place Details request is free, so this costs nothing but tells us
// which venues have quietly closed. Batched to keep one run well inside quota.
if ($placesConfigured) {
    Schedule::command('azp:places:refresh --older-than=30 --limit=500')
        ->dailyAt('04:15')
        // Worst case a run is 500 venues x 15s x 3 attempts; without this a hung
        // run would overlap the next day's and double the quota burn.
        ->withoutOverlapping(360)
        ->appendOutputTo(storage_path('logs/places-refresh.log'))
        ->onFailure(fn () => Log::error('azp:places:refresh failed — see logs/places-refresh.log'));

    // refresh() clears place_id when a place stops resolving, which drops the
    // venue out of its own whereNotNull('place_id') pool. Backfill is the only
    // way back, so it has to run on its own rather than waiting to be typed by
    // hand. Weekly and unmatched-excluded keeps the billed calls bounded.
    Schedule::command('azp:places:backfill --limit=200')
        ->weeklyOn(1, '04:45')
        ->withoutOverlapping(360)
        ->appendOutputTo(storage_path('logs/places-backfill.log'))
        ->onFailure(fn () => Log::error('azp:places:backfill failed — see logs/places-backfill.log'));
}

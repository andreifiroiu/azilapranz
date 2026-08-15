<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// An ID-only Place Details request is free, so this costs nothing but tells us
// which venues have quietly closed. Batched to keep one run well inside quota.
Schedule::command('azp:places:refresh --older-than=30 --limit=500')
    ->dailyAt('04:15')
    ->skip(fn () => blank(config('azp.google.places_key')));

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two gaps the first pass left open.
     *
     * `place_id_flag_reported_at`: the alert email decided what was "news" by
     * diffing against the stored status, then wrote the new status before
     * trying to send. A failed send was therefore unrecoverable — the next run
     * saw no change and stayed silent forever, while sync-status went on to act
     * on a closure nobody had been told about. Reporting is now a fact we
     * store, not one we infer.
     *
     * `previous_place_id`: an audit trail, not an automatic recovery path.
     * Nothing reads it — backfill still re-resolves through a billed search —
     * but a place ID that stopped resolving is the one piece of state you
     * cannot reconstruct by hand, and a re-search can match a *different*
     * venue. Keeping the outgoing value is what makes that diagnosable.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->timestamp('place_id_flag_reported_at')->nullable()->after('place_id_status_changed_at');
            $table->string('previous_place_id')->nullable()->after('place_id');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['place_id_flag_reported_at', 'previous_place_id']);
        });
    }
};

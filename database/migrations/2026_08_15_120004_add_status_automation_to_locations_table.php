<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bookkeeping for azp:places:sync-status, which writes the `status` column.
     *
     * A closed venue drops out of the listings but keeps its page, so the
     * command needs two things this table could not otherwise tell it: how long
     * a closure flag has been stable (a grace period against a Google glitch),
     * and which closures it applied itself (so it can reverse those and only
     * those, never a human's).
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // When place_id_status last changed value — not when it was last
            // checked, which is place_id_checked_at and moves every run.
            $table->timestamp('place_id_status_changed_at')->nullable()->after('place_id_checked_at');

            // The status this venue held before the command closed it. Non-null
            // is the marker that the closure was automatic, and the value is
            // what a restore puts back — venues are not all `active`, so
            // restoring to a constant would quietly promote them.
            $table->string('auto_closed_from', 32)->nullable()->after('place_id_status_changed_at');
            $table->timestamp('auto_closed_at')->nullable()->after('auto_closed_from');

            $table->index('auto_closed_from');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['auto_closed_from']);
            $table->dropColumn([
                'place_id_status_changed_at',
                'auto_closed_from',
                'auto_closed_at',
            ]);
        });
    }
};

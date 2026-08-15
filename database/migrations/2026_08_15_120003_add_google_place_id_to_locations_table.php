<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The place ID is the only Places field we are allowed to keep.
     *
     * Maps Platform General Service Terms §3 (Google ID Caching) permits
     * storing `place_id` indefinitely; everything else in a Places response is
     * covered by the EEA ToS §3.3.2(a)(iii) ban on saving business names,
     * addresses and reviews. So these columns hold an identifier and the
     * bookkeeping around checking it — never venue data.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('place_id')->nullable()->after('longitude');

            // ok | not_found | invalid | unmatched — see GooglePlaces.
            $table->string('place_id_status', 32)->nullable()->after('place_id');
            $table->timestamp('place_id_checked_at')->nullable()->after('place_id_status');

            $table->index('place_id');
            $table->index('place_id_status');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['place_id']);
            $table->dropIndex(['place_id_status']);
            $table->dropColumn(['place_id', 'place_id_status', 'place_id_checked_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('area_groups', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('area')->nullable();
            $table->string('city');
            $table->string('city_slug', 100);
            $table->string('generalarea')->nullable();
            $table->string('equivalents')->nullable();
            $table->timestamps();

            $table->index('city_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_groups');
    }
};

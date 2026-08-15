<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
            $table->string('name')->comment('URL slug, e.g. despre-noi');
            $table->longText('content');
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->boolean('published')->default(false);
            $table->boolean('is_sitemap')->default(true);
            $table->unsignedInteger('parent_page')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->string('language', 5)->default('ro');
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};

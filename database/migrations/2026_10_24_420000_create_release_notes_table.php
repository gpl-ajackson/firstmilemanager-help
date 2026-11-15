<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database copy of every Release Note (spec #30, Content library; plan, "Release Notes"):
 * one row per Stockist Release, its changes already grouped by Product Area and rendered.
 * Rebuilt from the content folder by help:rebuild, never edited by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('release_notes', function (Blueprint $table) {
            $table->date('released_on')->primary();
            $table->string('summary');
            $table->longText('intro_html');
            $table->json('areas');
            $table->boolean('affects_clients');
            $table->string('source_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_notes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records (spec #30): Page View Counts and Still Stuck Counts. Each row is a page (or an Article
 * Name), a day and a number, and nothing about the reader: no id, no time, no address, no
 * device. The day and the page are the key, so a view adds one to its row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('page_view_counts', function (Blueprint $table) {
            $table->string('page');
            $table->date('day');
            $table->unsignedInteger('views');
            $table->primary(['page', 'day']);
        });

        Schema::create('still_stuck_counts', function (Blueprint $table) {
            $table->string('article');
            $table->date('day');
            $table->unsignedInteger('clicks');
            $table->primary(['article', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('still_stuck_counts');
        Schema::dropIfExists('page_view_counts');
    }
};

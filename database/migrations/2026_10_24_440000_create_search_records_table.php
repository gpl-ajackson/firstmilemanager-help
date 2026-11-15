<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search Records (spec #30, "Search"): one row per settled Search, holding its cleaned words, how
 * many results were shown and whether a Glossary Entry was among them, its outcome, the result
 * opened with its place, the day and where it was made. Nothing about the reader: no time, no
 * address, no device, no session, no person, no 3PL.
 *
 * The id is a random version 4 UUID made by the server for the Search (never a time-ordered
 * one, which would hold the time); the result links on its results page carry it, which is how
 * an opened result finds its Search without anything on the reader's device.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('search_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('words');
            $table->unsignedSmallInteger('results_shown');
            $table->boolean('glossary_entry_shown');
            $table->string('outcome', 16);
            $table->string('opened_result')->nullable();
            $table->unsignedSmallInteger('opened_place')->nullable();
            $table->date('day')->index();
            $table->string('where_made', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_records');
    }
};

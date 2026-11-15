<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly totals (spec #30, "Keeping"): each month's Search Records and votes added up, kept for
 * good so the Help Gaps Report can compare a month with the same month a year earlier after the
 * records themselves are pruned. They hold numbers and no words: never a search's words, a
 * comment, a day or anything about a reader. A month is the first day of the month.
 *
 * - totalled_months: the months added up, so a month with nothing recorded reads as nothing
 *   rather than as not yet added up.
 * - monthly_search_totals: Searches by where they were made, outcome and whether a Glossary Entry
 *   was among the results.
 * - monthly_vote_totals: votes by Article (its Article Name), where they were given, answer and
 *   Feedback Reason.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('totalled_months', function (Blueprint $table) {
            $table->date('month')->primary();
        });

        Schema::create('monthly_search_totals', function (Blueprint $table) {
            $table->date('month');
            $table->string('where_made');
            $table->string('outcome');
            $table->boolean('glossary_entry_shown');
            $table->unsignedInteger('searches');
            $table->primary(['month', 'where_made', 'outcome', 'glossary_entry_shown']);
        });

        Schema::create('monthly_vote_totals', function (Blueprint $table) {
            $table->date('month')->index();
            $table->string('article');
            $table->string('where_given');
            $table->string('answer');
            $table->string('reason')->nullable();
            $table->unsignedInteger('votes');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_vote_totals');
        Schema::dropIfExists('monthly_search_totals');
        Schema::dropIfExists('totalled_months');
    }
};

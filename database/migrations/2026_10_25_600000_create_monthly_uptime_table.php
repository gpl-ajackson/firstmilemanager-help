<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly uptime (spec #30, user story 157; the "Help centre stays up" Reported Measure): one row
 * per month, read from the uptime monitor every night (help:record-uptime). The uptime is a
 * percentage of the month's uptime checks that passed, to four places, and through is the last UK
 * day it counts: the month's last day once the month is over. Kept for good, like the monthly
 * totals, and nothing about any reader. A month is the first day of the month.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('monthly_uptime', function (Blueprint $table) {
            $table->date('month')->primary();
            $table->decimal('uptime', 7, 4);
            $table->date('through');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_uptime');
    }
};

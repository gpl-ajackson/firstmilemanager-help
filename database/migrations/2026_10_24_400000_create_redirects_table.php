<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses that last (ADR-0001): what a Retired Article with no replacement tells readers,
 * and every old address that redirects for good. Both are rebuilt from the content folder by
 * help:rebuild, like the rest of the database copy.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->text('what_changed')->nullable();
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path')->unique();
            $table->string('to_path');
            $table->string('source_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');

        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('what_changed');
        });
    }
};

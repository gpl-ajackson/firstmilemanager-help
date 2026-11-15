<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database copy of every Glossary Entry, staff and client (spec #30, Content library), and
 * the terms each Article links to, from which an entry's "Articles that use it" is worked out.
 * Rebuilt from the content folder by help:rebuild, never edited by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('glossary_entries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('audience_tier');
            $table->string('term');
            $table->json('other_names');
            $table->json('former_names');
            $table->text('where_you_see_it');
            $table->json('status_values');
            $table->date('checked_on');
            $table->string('source_path');
            $table->longText('body_html');
            $table->unique(['audience_tier', 'name']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->json('terms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('terms');
        });

        Schema::dropIfExists('glossary_entries');
    }
};

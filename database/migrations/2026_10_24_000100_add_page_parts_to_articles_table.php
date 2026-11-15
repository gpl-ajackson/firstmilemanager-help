<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of an Article's header data (spec #30, "Content folder"), and its Section Names,
 * kept in the database copy so pages, listings and checks read them without the files.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->date('next_review_on')->nullable();
            $table->json('team_roles')->nullable();
            $table->json('source_references')->nullable();
            $table->json('related_articles')->nullable();
            $table->unsignedSmallInteger('path_position')->nullable();
            $table->json('messages')->nullable();
            $table->json('section_names')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['next_review_on', 'team_roles', 'source_references', 'related_articles', 'path_position', 'messages', 'section_names']);
        });
    }
};

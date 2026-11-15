<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database copy of every Article (spec #30, Content library): rebuilt from the content
 * folder by help:rebuild on each deploy, never edited by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('title');
            $table->string('summary');
            $table->string('type');
            $table->string('product_area');
            $table->string('audience_tier');
            $table->string('owner');
            $table->string('status');
            $table->date('checked_on');
            $table->string('source_path');
            $table->longText('body_html');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};

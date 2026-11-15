<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The help centre's facts (content/facts.yaml) in the database copy: one row, rebuilt by
 * help:rebuild, never edited by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('facts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description');
            $table->string('stage');
            $table->string('url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facts');
    }
};

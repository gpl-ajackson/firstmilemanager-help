<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Product Areas content/product-areas.yaml lists, in the app's menu order, which is the
 * order the site shows them in. Rebuilt by help:rebuild, never edited by hand.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_areas', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->unsignedInteger('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_areas');
    }
};

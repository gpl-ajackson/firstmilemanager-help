<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First Mile Manager's two visibility rules (ADR-0006, help#1): an Article's feature, which it
 * waits for on the demo (stored as a Draft till then, waiting_for_demo saying why), and whether
 * it is Joint-only, opened by its address but never listed, searched or in the sitemap.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('feature')->nullable();
            $table->boolean('joint_only')->default(false);
            $table->boolean('waiting_for_demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn(['feature', 'joint_only', 'waiting_for_demo']);
        });
    }
};

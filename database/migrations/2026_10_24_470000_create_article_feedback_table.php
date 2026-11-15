<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Article Feedback (spec #30): one row per vote. The Article (by its Article Name), Yes or No,
 * the Feedback Reason and comment a No may add, the Article's Checked On date when the vote was
 * given, the UK day and where it was given. Nothing about the reader: no time, address, device
 * or session. The id is random; the page that follows a No carries it so the reader's reason
 * and comment join their own vote, and it says nothing about who gave it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('article_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('article');
            $table->string('answer');
            $table->string('reason')->nullable();
            $table->string('comment', 500)->nullable();
            $table->date('checked_on');
            $table->date('day');
            $table->string('where_given');
            $table->index(['day', 'article']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_feedback');
    }
};

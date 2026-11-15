<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Doesn't match" issues (#48): whether a vote whose Feedback Reason is "It doesn't match what I
 * see" has reached the Article Owner through the issue desk, as a new issue or a comment on the
 * open one. A vote that couldn't be sent (GitHub unreachable) stays false and is sent on the next
 * run. It holds no time and nothing about the reader; pruning a vote's row removes it too.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('article_feedback', function (Blueprint $table) {
            $table->boolean('sent_to_issue')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('article_feedback', function (Blueprint $table) {
            $table->dropColumn('sent_to_issue');
        });
    }
};

<?php

namespace App\Records\Console;

use App\Records\Records;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Lets the Content Owner delete one feedback comment sooner than its six months (spec #30,
 * "Keeping"; user story 101), for a reader who asks or a comment holding personal details. The
 * privacy notice asks readers for the Article and the date, so the command first lists that
 * Article's comments with each vote's reference, then deletes the one named with --vote. The
 * vote and its Feedback Reason stay. It never asks a question, so it works from Forge's
 * command runner.
 */
final class DeleteCommentCommand extends Command
{
    protected $signature = 'help:delete-comment
        {article : The Article Name the comment was left on}
        {--day= : List only that day\'s comments (YYYY-MM-DD)}
        {--vote= : Delete the comment of the vote with this reference}';

    protected $description = 'List an Article\'s feedback comments, or delete one by its vote\'s reference';

    public function handle(Records $records): int
    {
        $article = (string) $this->argument('article');
        $vote = $this->option('vote');

        if (is_string($vote) && $vote !== '') {
            $deleted = $records->deleteComment($article, $vote);
            if ($deleted === null) {
                $this->error("No comment on {$article} has that reference. List them with: php artisan help:delete-comment {$article}");

                return self::FAILURE;
            }
            $this->info("Deleted the comment “{$deleted}” from vote {$vote} on {$article}. The vote and its reason stay.");

            return self::SUCCESS;
        }

        $day = $this->option('day');
        if (is_string($day) && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $this->error('Give the day as YYYY-MM-DD.');

            return self::FAILURE;
        }
        $comments = $records->comments($article, is_string($day) ? CarbonImmutable::parse($day) : null);
        if ($comments === []) {
            $this->info("No comments on {$article}".(is_string($day) ? " on {$day}" : '').'.');

            return self::SUCCESS;
        }

        $this->table(['Reference', 'Day', 'Reason', 'Comment'], array_map(fn ($comment): array => [
            $comment->reference,
            $comment->day->toDateString(),
            $comment->reason->value ?? '',
            $comment->comment,
        ], $comments));
        $this->line("Delete one with: php artisan help:delete-comment {$article} --vote=<reference>");

        return self::SUCCESS;
    }
}

<?php

namespace App\IssueDesk;

/**
 * Words from outside the help centre's own code, written into an issue so GitHub shows them as
 * they are: a reader's comment or Search, a pull request's title, a commit's subject or a login.
 * As code, they can't mention anyone, link an issue, close one, add markup or hide in a comment.
 */
final class IssueText
{
    /**
     * Words as a block of their own, such as a reader's comment: a fence longer than any run of
     * backticks in them keeps them as their words, line breaks and all.
     */
    public static function quoted(string $words): string
    {
        preg_match_all('/`+/', $words, $runs);
        $fence = str_repeat('`', max([3, ...array_map(fn (string $run): int => strlen($run) + 1, $runs[0])]));

        return "{$fence}text\n{$words}\n{$fence}";
    }

    /** Words on one line, such as a Search or a title, as code. */
    public static function code(string $words): string
    {
        $words = trim(str_replace(['`', "\r", "\n"], ["'", ' ', ' '], $words));

        return $words === '' ? '(untitled)' : "`{$words}`";
    }
}

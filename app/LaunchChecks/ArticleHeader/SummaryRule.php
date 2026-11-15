<?php

namespace App\LaunchChecks\ArticleHeader;

use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Findings;

/**
 * summary: one sentence (Launch Check 1). Its length is the content library's rule; whether it
 * is one sentence is checked here, so a deploy never turns on how a sentence is told apart.
 */
final readonly class SummaryRule implements HeaderRule
{
    public function field(): string
    {
        return 'summary';
    }

    public function check(ArticleFile $article, Findings $findings): void
    {
        $summary = $article->text('summary');
        if ($summary !== null && preg_match('/[.!?]\s+\S/', $summary) === 1) {
            $findings->fail('header.summary', $article->file, $article->lineOf('summary'), 'must be one sentence: what the reader will be able to do or know');
        }
    }
}

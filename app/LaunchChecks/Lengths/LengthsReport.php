<?php

namespace App\LaunchChecks\Lengths;

use App\LaunchChecks\ArticleBody;
use App\LaunchChecks\ArticleTemplate\ArticleTemplates;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;

/**
 * Launch Check 1's lengths, reported for the reviewer and never failing (the plan's review
 * step 3, "Reads right"): sentences over the Style Guide's 20 words, runs of more than ten
 * steps, and Articles over their type's guide length, which the Article Template gives.
 */
final class LengthsReport implements Check
{
    /** The Style Guide's limits (the plan's "Writing style and templates", #22). */
    public const SENTENCE_WORDS = 20;

    public const STEPS = 10;

    public function run(CheckContext $context, Findings $findings): void
    {
        $templates = ArticleTemplates::read($context->templatesPath, new Findings); // The template check names template problems.

        foreach ($context->articles() as $article) {
            $body = ArticleBody::of($article);

            foreach ($body->sentences() as $sentence) {
                if ($sentence['words'] > self::SENTENCE_WORDS) {
                    $findings->report('length.sentence', $article->file, $sentence['line'],
                        "a sentence of {$sentence['words']} words, over the Style Guide's ".self::SENTENCE_WORDS.': "'.self::opening($sentence['text']).'"');
                }
            }

            foreach ($body->stepRuns() as $run) {
                if ($run['steps'] > self::STEPS) {
                    $findings->report('length.steps', $article->file, $run['line'],
                        "a run of {$run['steps']} steps, over the Style Guide's ten: split the task into parts, each under a heading");
                }
            }

            $type = $article->text('type');
            $guide = $type === null ? null : $templates->for($type)?->guideWords;
            if ($guide !== null && ($words = $body->words()) > $guide) {
                $findings->report('length.article', $article->file, 1,
                    "about {$words} words, over the {$type} guide of about {$guide}");
            }
        }
    }

    /** The sentence's first eight words, enough to find it. */
    private static function opening(string $sentence): string
    {
        $words = explode(' ', $sentence);

        return count($words) > 8 ? implode(' ', array_slice($words, 0, 8)).'…' : $sentence;
    }
}

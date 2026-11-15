<?php

namespace App\IssueDesk;

use App\Content\Article;
use App\Content\AudienceTier;
use App\Records\DoesntMatchVote;
use App\Records\FeedbackReason;
use Carbon\CarbonInterface;

/**
 * The words of a "doesn't match" issue and of the comments later votes add to it (spec #30,
 * user stories 70 to 72): which Article, when, the reader's comment kept as their words, and
 * what the Article Owner does next, with rule 4's five business days starting when the owner
 * confirms the mismatch.
 */
final class DoesntMatchIssue
{
    /** How every "doesn't match" issue's body starts, whatever its Article. */
    public const MARKER_PREFIX = '<!-- help-doesnt-match:';

    /**
     * The hidden first line of every "doesn't match" issue, naming its Article, so the desk finds
     * the Article's open issue again (and the Help Gaps Report finds the month's issues by the
     * prefix "<!-- help-doesnt-match:").
     */
    public static function marker(string $articleName): string
    {
        return self::MARKER_PREFIX."{$articleName} -->";
    }

    public static function title(string $articleName, ?Article $article): string
    {
        return "Doesn't match what readers see: ".($article->title ?? $articleName);
    }

    public static function body(DoesntMatchVote $vote, ?Article $article): string
    {
        $reason = FeedbackReason::DoesntMatch->value;
        $opening = $article === null
            ? "A reader chose \"{$reason}\" on `{$vote->article}`. There is no Article named `{$vote->article}` in the help centre now, so this is assigned to the Content Owner."
            : "A reader chose \"{$reason}\" on [{$article->title}](".url("/articles/{$article->name}").') (`content/'.$article->source_path.'`, '.self::tier($article).').';

        return implode("\n\n", [
            self::marker($vote->article)."\n".$opening,
            self::when($vote),
            self::readersComment($vote->comment),
            '## What to do',
            'Check the Article with a Demo Walk: follow its steps in a freshly seeded Trentside Logistics demo, as the Team Role it is tagged for.',
            "Rule 4's five business days start when you confirm the mismatch, not when this issue was opened. Say so in a comment here when you confirm it, then:",
            implode("\n", [
                '- If the Article is wrong, fix it or retire it within five business days.',
                '- If First Mile Manager is wrong, raise it on the main project (gpl-ajackson/firstmilemanager) and link that issue here.',
                '- If nothing is wrong, close this issue with a note saying why.',
            ]),
            "More \"doesn't match\" votes on this Article are added here as comments while this issue is open. Once it is closed, the next one opens a new issue.",
        ])."\n";
    }

    /** What a later vote adds to the Article's open issue. */
    public static function comment(DoesntMatchVote $vote): string
    {
        return implode("\n\n", [
            "Another reader says this Article doesn't match what they see.",
            self::when($vote),
            self::readersComment($vote->comment),
        ])."\n";
    }

    private static function readersComment(?string $comment): string
    {
        if ($comment === null) {
            return 'The reader added no comment.';
        }

        // Kept as the reader's words: no markup, links, mentions or issue references.
        return "Their comment:\n\n".IssueText::quoted($comment);
    }

    private static function when(DoesntMatchVote $vote): string
    {
        return '- Voted on '.self::date($vote->day).', when the Article was last checked on '.self::date($vote->checkedOn).'.';
    }

    private static function tier(Article $article): string
    {
        return $article->audience_tier === AudienceTier::Client
            ? "Client tier: the reader is one of the Operator's clients"
            : 'Staff';
    }

    private static function date(CarbonInterface $day): string
    {
        return $day->format('j F Y');
    }
}

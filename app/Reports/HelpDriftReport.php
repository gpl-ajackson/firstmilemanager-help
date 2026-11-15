<?php

namespace App\Reports;

use App\IssueDesk\IssueText;
use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Finding;
use App\LaunchChecks\Findings;
use App\LaunchChecks\Glossary\GlossaryCheck;
use App\LaunchChecks\Pictures\PicturesCheck;
use App\LaunchChecks\SourceReferences\MainProjectIssues;
use App\LaunchChecks\SourceReferences\SourceReferencesCheck;
use App\Records\Records;
use App\Reports\Drift\DriftLinks;
use App\Reports\Drift\DriftReport;
use App\Reports\Drift\DriftSection;
use Carbon\CarbonImmutable;

/**
 * The Help Drift Report (spec #30 user stories 148 and 149, plan S7 step 9): once a month,
 * where help may have fallen behind Stockist, from both repositories and never from Stockist
 * itself (rule 21). Each section lists its items with a link to where each one is:
 *
 * 1. Published Articles whose Source References changed in the main project after their Checked
 *    On: a commit to a cited plan, ADR or other file (Help content sections aside), or an edit
 *    to a cited issue's description (a spec's);
 * 2. Source References that don't match both ways or lead nowhere (Launch Check 2);
 * 3. main-project pull requests merged into main that month whose Help Proposal isn't merged,
 *    or that carry no help line;
 * 4. Published Articles whose next review date has come;
 * 5. Pictures that differ from what their Recipes take from main, and Recipes that broke
 *    (Launch Check 12 retaking every Picture, the Picture Check);
 * 6. what the Glossary Check reports (Launch Check 4).
 *
 * The workflow .github/workflows/help-drift-report.yml raises it on the first working day of
 * each month (help:drift-report). The Help Gaps Report (#67) finds the month's issue by its
 * label (LABEL) and marker (marker()).
 */
final readonly class HelpDriftReport
{
    /** The label on every Help Drift Report issue. */
    public const LABEL = 'help-drift-report';

    public function __construct(
        private SourceReferencesCheck $sourceReferences,
        private GlossaryCheck $glossary,
        private PicturesCheck $pictures,
    ) {}

    /** The hidden first line of the month's issue: "<!-- help-drift-report:2026-11 -->". */
    public static function marker(ReportMonth $month): string
    {
        return '<!-- '.self::LABEL.':'.$month->key().' -->';
    }

    /** "Help drift: November 2026", as the plan names it. */
    public static function title(ReportMonth $month): string
    {
        return 'Help drift: '.$month->name();
    }

    /**
     * @param  GitHubReader|null  $stockist  the main project on GitHub, with the read-only key; null without one
     * @param  GitHubReader|null  $supportSite  this repository on GitHub (its Help Proposals); null without a key
     * @param  string  $mainProjectRef  the branch the checkout is of, for the report
     */
    public function make(
        ReportMonth $month,
        CarbonImmutable $day,
        CheckContext $context,
        DriftLinks $links,
        ?GitHubReader $stockist,
        ?GitHubReader $supportSite,
        string $mainProjectRef = 'main',
    ): DriftReport {
        return new DriftReport($month, $day, [
            $this->sourcesChanged($context, $links, $stockist),
            $this->fromCheck('Source References that don\'t match both ways', $this->sourceReferences->run(...), $context, $links),
            $this->mergedWithoutHelp($month, $links, $stockist, $supportSite),
            $this->pastReview($day, $context, $links),
            $this->fromCheck("Pictures that differ from `{$mainProjectRef}`", $this->pictures->run(...), $context, $links),
            $this->fromCheck('The Glossary Check\'s reports', $this->glossary->run(...), $context, $links),
        ], $links, $mainProjectRef);
    }

    private function sourcesChanged(CheckContext $context, DriftLinks $links, ?GitHubReader $stockist): DriftSection
    {
        $heading = 'Articles whose sources changed since their Checked On';
        $notChecked = [];
        try {
            $history = MainProjectHistory::of($context->mainProject->path);
        } catch (UnreadableHistory $unreadable) {
            $history = null;
            $notChecked[] = "the plans, ADRs and other files Articles cite: {$unreadable->getMessage()}";
        }

        /** @var list<array{article: ArticleFile, checkedOn: CarbonImmutable, reference: string}> $cited */
        $cited = [];
        $issues = [];
        foreach ($this->published($context) as $article) {
            $checkedOn = self::day($article->header['checked_on'] ?? null);
            $references = $article->header['source_references'] ?? null;
            if ($checkedOn === null || ! is_array($references)) {
                continue;
            }
            foreach (array_unique(array_filter($references, is_string(...))) as $reference) {
                $cited[] = ['article' => $article, 'checkedOn' => $checkedOn, 'reference' => $reference];
                if (preg_match('/\Afmm#(\d+)\z/', $reference, $issue) === 1) {
                    $issues[] = (int) $issue[1];
                }
            }
        }

        $edited = [];
        $issues = array_values(array_unique($issues));
        if ($issues !== [] && $stockist === null) {
            $notChecked[] = 'the main project\'s issues Articles cite (fmm#'.implode(', fmm#', $issues).'): there is no FMM_READ_TOKEN to read them from GitHub with';
        } elseif ($issues !== []) {
            try {
                $edited = $stockist->lastEdited(MainProjectIssues::REPOSITORY, $issues);
            } catch (GitHubUnreadable $unreadable) {
                $notChecked[] = "the main project's issues Articles cite: {$unreadable->getMessage()}";
            }
        }

        $items = [];
        foreach ($cited as ['article' => $article, 'checkedOn' => $checkedOn, 'reference' => $reference]) {
            $where = '['."`{$article->file}`".']('.$links->supportSiteFile($article->file).') (Checked On '.$checkedOn->format('j F Y').')';
            if (preg_match('/\Afmm#(\d+)\z/', $reference, $issue) === 1) {
                $editedAt = $edited[(int) $issue[1]] ?? null;
                if ($editedAt !== null && $editedAt->setTimezone(Records::TIMEZONE)->startOfDay()->greaterThan($checkedOn)) {
                    $items[] = "{$where} cites [{$reference}]({$links->mainProjectIssue((int) $issue[1])}): its description was edited on ".$editedAt->setTimezone(Records::TIMEZONE)->format('j F Y');
                }

                continue;
            }
            $path = preg_match('/\AADR-(\d{4})\z/', $reference, $adr) === 1 ? $context->mainProject->adr((int) $adr[1]) : $reference;
            if ($history === null || $path === null || $context->mainProject->read($path) === null) {
                continue;
            }
            $changes = $history->changesAfter($path, $checkedOn);
            if ($changes === []) {
                continue;
            }
            $latest = $changes[0];
            $items[] = "{$where} cites [`{$reference}`]({$links->mainProjectHistory($path)}): ".count($changes).' '.str('commit')->plural(count($changes)).' since, the latest '
                .'[`'.substr($latest->sha, 0, 7)."`]({$links->mainProjectCommit($latest->sha)}) on ".$latest->at->setTimezone(Records::TIMEZONE)->format('j F Y')
                .' ('.IssueText::code($latest->subject).')';
        }

        return new DriftSection($heading, $items, $notChecked);
    }

    private function mergedWithoutHelp(ReportMonth $month, DriftLinks $links, ?GitHubReader $stockist, ?GitHubReader $supportSite): DriftSection
    {
        $heading = 'Main-project changes merged without their help';
        if ($stockist === null) {
            return new DriftSection($heading, [], ['there is no FMM_READ_TOKEN to read the main project\'s pull requests with']);
        }
        if ($supportSite === null) {
            return new DriftSection($heading, [], ['there is no GITHUB_TOKEN to read this repository\'s Help Proposals with']);
        }

        try {
            $items = [];
            foreach ($stockist->mergedPullRequests(MainProjectIssues::REPOSITORY, 'main', $month->first, $month->end()) as $change) {
                if ($change->isExempt()) {
                    continue;
                }
                $what = "[fmm#{$change->number}]({$links->mainProjectPullRequest($change->number)}) ".IssueText::code($change->title)
                    .', merged on '.$change->mergedAt->setTimezone(Records::TIMEZONE)->format('j F Y');
                $proposals = $change->helpProposals($links->supportSite);
                if ($proposals === []) {
                    if (! $change->saysNoHelpChanges()) {
                        $items[] = "{$what}: its description neither links a Help Proposal nor says `No help changes: <reason>`";
                    }

                    continue;
                }
                foreach ($proposals as $number) {
                    $state = $supportSite->pullRequestState($links->supportSite, $number);
                    $proposal = "[Help Proposal #{$number}]({$links->supportSitePullRequest($number)})";
                    $problem = match ($state) {
                        'merged' => null,
                        'open' => "its {$proposal} is still open",
                        'closed' => "its {$proposal} was closed without merging",
                        default => "it links {$proposal}, which this repository hasn't got",
                    };
                    if ($problem !== null) {
                        $items[] = "{$what}: {$problem}";
                    }
                }
            }
        } catch (GitHubUnreadable $unreadable) {
            return new DriftSection($heading, [], ["the pull requests merged in {$month->name()} couldn't be read: {$unreadable->getMessage()}"]);
        }

        return new DriftSection($heading, $items);
    }

    private function pastReview(CarbonImmutable $day, CheckContext $context, DriftLinks $links): DriftSection
    {
        $items = [];
        foreach ($this->published($context) as $article) {
            $reviewOn = self::day($article->header['next_review_on'] ?? null);
            if ($reviewOn === null || $reviewOn->greaterThan($day)) {
                continue;
            }
            $owner = is_string($article->header['owner'] ?? null) ? $article->header['owner'] : null;
            $items[] = '[`'.$article->file.'`]('.$links->supportSiteFile($article->file, $article->lineOf('next_review_on')).')'
                .': its review was due on '.$reviewOn->format('j F Y')
                .($owner === null ? '' : ' (owner '.IssueText::code($owner).')');
        }

        return new DriftSection('Articles past their review date', $items);
    }

    /**
     * A Launch Check's findings, failures and reports alike, each linked to where it points.
     *
     * @param  callable(CheckContext, Findings): void  $check
     */
    private function fromCheck(string $heading, callable $check, CheckContext $context, DriftLinks $links): DriftSection
    {
        $findings = new Findings;
        $check($context, $findings);

        $items = array_map(fn (Finding $finding): string => '[`'.$finding->where().'`]('.$links->forFinding($finding).') **'.$finding->rule.'**: '
            .htmlspecialchars(str_replace(["\r", "\n"], ' ', $finding->message), ENT_NOQUOTES)
            .(in_array($finding->rule, [PicturesCheck::RETAKEN, PicturesCheck::BROKEN], true) && $links->runUrl !== null
                ? " ([the images are in this run's picture-check artefact]({$links->runUrl}))" : ''),
            [...$findings->failures(), ...$findings->reports()]);

        return new DriftSection($heading, $items, array_map(fn (string $why): string => rtrim($why, '.'), $findings->checksNotRun()));
    }

    /** @return list<ArticleFile> */
    private function published(CheckContext $context): array
    {
        return array_values(array_filter($context->articles(), fn (ArticleFile $article): bool => ($article->header['status'] ?? null) === 'Published'));
    }

    /** A header date as written (YYYY-MM-DD; YAML may have read it as a timestamp), as a UK day. */
    private static function day(mixed $value): ?CarbonImmutable
    {
        $date = is_int($value) ? gmdate('Y-m-d', $value) : $value;
        if (! is_string($date) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
            return null;
        }

        return CarbonImmutable::parse($date, Records::TIMEZONE)->startOfDay();
    }
}

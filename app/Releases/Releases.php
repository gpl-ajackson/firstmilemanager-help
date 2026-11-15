<?php

namespace App\Releases;

use App\Content\ChangeSummarySource;
use App\Content\ContentLibrary;
use App\Content\InvalidContent;
use App\Content\ReleaseNoteSource;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\LaunchChecks;
use App\Releases\Check\ChangeHelp;
use App\Releases\Check\GitHubUnreadable;
use App\Releases\Check\HelpLine;
use App\Releases\Check\InvalidRelease;
use App\Releases\Check\MainProjectHistory;
use App\Releases\Check\PullRequests;
use App\Releases\Check\ReleaseCheck;
use App\Releases\Check\ReleasedChange;
use Carbon\CarbonImmutable;
use Symfony\Component\Yaml\Yaml;

/**
 * Release tooling (spec #30, Modules; ADR-0003): what happens at a FMM Release. Today it
 * assembles the Release Note from the Change Summaries the Help Proposals left in the Next
 * Release folder, and the Release Check (#68) shows that same draft beside every main-project
 * change since the last release with its help status and the Launch Checks against the version
 * being released.
 *
 * Publishing writes content/release-notes/<yyyy-mm-dd>.md and removes the Change Summaries it
 * gathered in the same change, so nothing is announced twice. It works on the files only: the
 * change that holds them is reviewed and merged like any other, and its deploy publishes it.
 */
final class Releases
{
    /** A Release Note's summary is one sentence of at most this many characters. */
    private const SUMMARY_MAX = ReleaseNoteSource::SUMMARY_MAX;

    /** The UK's day decides a release's date. */
    public const string TIMEZONE = 'Europe/London';

    public function __construct(
        private readonly ContentLibrary $library,
        private readonly LaunchChecks $launchChecks,
    ) {}

    /**
     * A FMM Release's date as written (YYYY-MM-DD), or today in the UK when none is given;
     * null when it isn't a real date written that way.
     */
    public static function releaseDate(?string $written): ?CarbonImmutable
    {
        if ($written === null || $written === '') {
            return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        }

        $date = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $written) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $written, self::TIMEZONE) : null;

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $written ? $date : null;
    }

    /**
     * The Release Check (ADR-0003; spec #30 user stories 145 and 149): before promoting the main
     * project's checkout at $launchChecks->mainProject (its HEAD is the version being released)
     * to the demo branch, every change on main since $lastRelease (origin/demo) with its help
     * status, the Release Note the Next Release folder would make on $date, and the Launch
     * Checks over $launchChecks (which should retake every Picture). Stockist is read only
     * through its repository: the checkout's history and GitHub's pull requests. Nothing is
     * written.
     *
     * @throws InvalidRelease when the checkout or the last release can't be found in it.
     */
    public function checkRelease(CheckContext $launchChecks, string $lastRelease, CarbonImmutable $date, PullRequests $github): ReleaseCheck
    {
        $history = new MainProjectHistory($launchChecks->mainProject->path);
        $release = $history->commitOf('HEAD');
        $lastReleaseCommit = $history->commitOf($lastRelease);

        $changes = [];
        $seen = [];
        foreach ($history->commitsBetween($lastReleaseCommit, $release) as $commit) {
            $help = $this->helpOf($commit, $github);
            $number = $help->change->pullRequest;
            if ($number !== null && isset($seen[$number])) {
                continue;
            }
            $seen[$number ?? $commit['commit']] = true;
            $changes[] = $help;
        }

        $draft = null;
        $problems = [];
        try {
            $draft = $this->draftReleaseNote($launchChecks->contentPath, $date);
        } catch (InvalidContent $invalid) {
            $problems = $invalid->problems;
        }

        return new ReleaseCheck(
            release: $release,
            lastReleaseRef: $lastRelease,
            lastRelease: $lastReleaseCommit,
            changes: $changes,
            draft: $draft,
            releaseNoteProblems: $problems,
            findings: $this->launchChecks->run($launchChecks),
        );
    }

    /** @param  array{commit: string, pullRequest: ?int, subject: string, message: string}  $commit */
    private function helpOf(array $commit, PullRequests $github): ChangeHelp
    {
        $number = $commit['pullRequest'];
        $named = new ReleasedChange($commit['commit'], $number, self::titleOf($commit), $commit['message']);
        if (! $github->readsStockist()) {
            return ChangeHelp::unread($named, 'FMM_READ_TOKEN is not set, so the main project\'s pull requests on GitHub can\'t be read');
        }

        try {
            $number ??= $github->mainProjectPullRequestOf($commit['commit']);
            $change = $number === null
                ? new ReleasedChange($commit['commit'], null, $commit['subject'], $commit['message'])
                : $github->mainProjectPullRequest($number, $commit['commit']);
            if ($change === null) {
                return ChangeHelp::unread(new ReleasedChange($commit['commit'], $number, $named->title, ''),
                    'GitHub shows no pull request #'.$number.' in '.MainProjectHistory::REPOSITORY.' (or FMM_READ_TOKEN can\'t read it)');
            }

            $line = HelpLine::of($change->description);
            $proposals = array_map(fn (int $proposal) => $github->helpProposal($proposal), $line->helpProposals);
        } catch (GitHubUnreadable $unreadable) {
            return ChangeHelp::unread($named, $unreadable->getMessage());
        }

        return ChangeHelp::of($change, $line, $proposals);
    }

    /**
     * A change's title from its commit alone: GitHub's merge commit has the pull request's title
     * on its first line after the subject.
     *
     * @param  array{commit: string, pullRequest: ?int, subject: string, message: string}  $commit
     */
    private static function titleOf(array $commit): string
    {
        if (str_starts_with($commit['subject'], 'Merge pull request #')) {
            foreach (array_slice(preg_split('/\R/', $commit['message']) ?: [], 1) as $line) {
                if (trim($line) !== '') {
                    return trim($line);
                }
            }
        }

        return $commit['subject'];
    }

    /**
     * The Release Note the Next Release folder of $contentPath would make for a FMM Release
     * on $date, without writing anything; null when no Change Summary is waiting.
     *
     * @throws InvalidContent when a Change Summary is one the rebuild would refuse.
     */
    public function draftReleaseNote(string $contentPath, CarbonImmutable $date): ?DraftReleaseNote
    {
        $waiting = $this->library->nextRelease($contentPath);
        if ($waiting === []) {
            return null;
        }

        $areas = array_values(array_unique(array_map(fn (ChangeSummarySource $source): string => $source->summary->productArea, $waiting)));
        $header = [
            'summary' => self::summaryOf($areas),
            'changes' => array_map(fn (ChangeSummarySource $source): array => array_filter([
                'product_area' => $source->summary->productArea,
                'team_roles' => $source->summary->teamRoles,
                'affects_clients' => $source->summary->affectsClients,
                'articles' => $source->summary->articles,
                'text' => $source->summary->text,
            ], fn (mixed $value): bool => $value !== []), $waiting),
        ];

        return new DraftReleaseNote(
            date: $date,
            file: ReleaseNoteSource::FOLDER.'/'.$date->toDateString().'.md',
            contents: "---\n".rtrim(Yaml::dump($header, 3, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_COMPACT_NESTED_MAPPING))."\n---\n",
            changeSummaries: $waiting,
        );
    }

    /**
     * Writes the Release Note for a FMM Release on $date into $contentPath and removes the
     * Change Summaries it gathered. Returns what it wrote, or null (writing nothing) when no
     * Change Summary is waiting.
     *
     * @throws InvalidContent when a Change Summary is one the rebuild would refuse (nothing is written).
     * @throws ReleaseNoteExists when that date already has a Release Note (nothing is written).
     */
    public function publishReleaseNote(string $contentPath, CarbonImmutable $date): ?DraftReleaseNote
    {
        $contentPath = rtrim($contentPath, '/');
        $draft = $this->draftReleaseNote($contentPath, $date);
        if ($draft === null) {
            return null;
        }
        $path = $contentPath.'/'.$draft->file;
        if (file_exists($path)) {
            throw new ReleaseNoteExists("{$draft->file} already exists, and a Release Note is never replaced");
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $draft->contents);
        foreach ($draft->changeSummaries as $source) {
            unlink($contentPath.'/'.$source->file);
        }

        return $draft;
    }

    /**
     * A first summary for What's new, naming the Product Areas the release changed, for the
     * Content Owner to reword before publishing.
     *
     * @param  list<string>  $areas
     */
    private static function summaryOf(array $areas): string
    {
        $last = array_pop($areas);
        $summary = 'What changed in '.($areas === [] ? $last : implode(', ', $areas).' and '.$last).'.';

        return mb_strlen($summary) <= self::SUMMARY_MAX ? $summary : 'What changed in '.(count($areas) + 1).' Product Areas.';
    }
}

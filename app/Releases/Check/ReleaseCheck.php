<?php

namespace App\Releases\Check;

use App\Content\ContentProblem;
use App\LaunchChecks\Finding;
use App\LaunchChecks\Findings;
use App\LaunchChecks\Pictures\PicturesCheck;
use App\Releases\DraftReleaseNote;
use Illuminate\Support\Str;

/**
 * What the Release Check found before a FMM Release (ADR-0003): every change since the
 * last release with its help status, the draft Release Note, and the Launch Checks with every
 * Picture retaken against the version being released. Help is complete only when every change's
 * help is merged or it says "No help changes", the Change Summaries can be published, and the
 * Launch Checks pass.
 */
final readonly class ReleaseCheck
{
    /** The Picture Check's rules: a Picture that differs, a Recipe that broke, or no Stockist to take them in. */
    public const array PICTURE_RULES = [PicturesCheck::RETAKEN, PicturesCheck::BROKEN, PicturesCheck::STOCKIST];

    /**
     * @param  list<ChangeHelp>  $changes  oldest first
     * @param  list<ContentProblem>  $releaseNoteProblems  what the rebuild refuses in the Next Release folder
     */
    public function __construct(
        /** The main-project commit being released. */
        public string $release,
        /** Where the last FMM Release is (origin/demo) and the commit it named. */
        public string $lastReleaseRef,
        public string $lastRelease,
        public array $changes,
        public ?DraftReleaseNote $draft,
        public array $releaseNoteProblems,
        public Findings $findings,
    ) {}

    /** @return list<Finding> The Picture Check's failures: Pictures that differ at the version being released, and Recipes that broke. */
    public function pictures(): array
    {
        return array_values(array_filter($this->findings->failures(), fn (Finding $finding): bool => in_array($finding->rule, self::PICTURE_RULES, true)));
    }

    /** @return list<Finding> Every other Launch Check finding: failures, then reports. */
    public function contentFindings(): array
    {
        return array_values(array_filter([...$this->findings->failures(), ...$this->findings->reports()],
            fn (Finding $finding): bool => ! ($finding->fails && in_array($finding->rule, self::PICTURE_RULES, true))));
    }

    public function isComplete(): bool
    {
        return $this->whyNotComplete() === [];
    }

    /** @return list<string> Each reason help isn't complete yet, counted; empty when it is. */
    public function whyNotComplete(): array
    {
        $why = [];
        foreach ([
            HelpStatus::Missing->value => ['change has no help', 'changes have no help'],
            HelpStatus::Waiting->value => ['change waits for its Help Proposal to merge', 'changes wait for their Help Proposals to merge'],
            HelpStatus::Unread->value => ['change\'s help status couldn\'t be read', 'changes\' help statuses couldn\'t be read'],
        ] as $status => [$one, $many]) {
            $count = count(array_filter($this->changes, fn (ChangeHelp $help): bool => $help->status->value === $status));
            if ($count > 0) {
                $why[] = $count.' '.($count === 1 ? $one : $many);
            }
        }
        if ($this->releaseNoteProblems !== []) {
            $why[] = 'the Next Release folder has Change Summaries the rebuild would refuse';
        }
        $differ = count(array_filter($this->pictures(), fn (Finding $finding): bool => $finding->rule === PicturesCheck::RETAKEN));
        if ($differ > 0) {
            $why[] = $differ.' '.Str::plural('Picture', $differ).' '.($differ === 1 ? 'differs' : 'differ').' at the version being released';
        }
        $others = count($this->findings->failures()) - $differ;
        if ($others > 0) {
            $why[] = $others.' other Launch Check '.Str::plural('failure', $others);
        }

        return $why;
    }

    /** The plain answer: whether help is complete for this release, and if not, why. */
    public function verdict(): string
    {
        return $this->isComplete()
            ? 'Help is complete for this release: every change has its help merged or says "No help changes", and the Launch Checks pass against Stockist '.substr($this->release, 0, 12).'.'
            : 'Help is not complete for this release: '.implode('; ', $this->whyNotComplete()).'. Release when each is put right and this check passes.';
    }
}

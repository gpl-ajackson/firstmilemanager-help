<?php

namespace App\Releases\Check;

/** A released change with its help status, and the words that say why. */
final readonly class ChangeHelp
{
    /** Dependabot's pull requests are exempt from the main project's Help content check. */
    public const string DEPENDABOT = 'dependabot[bot]';

    /**
     * @param  list<HelpProposal>  $helpProposals
     */
    private function __construct(
        public ReleasedChange $change,
        public HelpStatus $status,
        public string $why,
        public array $helpProposals = [],
    ) {}

    /** @param  list<HelpProposal>  $helpProposals  every Help Proposal its help line links, as GitHub has it */
    public static function of(ReleasedChange $change, HelpLine $line, array $helpProposals): self
    {
        if ($helpProposals !== []) {
            $statuses = array_map(fn (HelpProposal $proposal): HelpStatus => $proposal->status(), $helpProposals);
            $status = in_array(HelpStatus::Missing, $statuses, true) ? HelpStatus::Missing
                : (in_array(HelpStatus::Waiting, $statuses, true) ? HelpStatus::Waiting : HelpStatus::Complete);
            $why = (count($helpProposals) === 1 ? 'Help Proposal ' : 'Help Proposals ')
                .implode('; ', array_map(fn (HelpProposal $proposal): string => "{$proposal->reference()}, {$proposal->describe()}", $helpProposals));

            return new self($change, $status, $why, $helpProposals);
        }
        if ($line->noHelpChanges !== null) {
            return new self($change, HelpStatus::Complete, "No help changes: {$line->noHelpChanges}");
        }
        if ($change->author === self::DEPENDABOT) {
            return new self($change, HelpStatus::Complete, "Dependabot's dependency update (exempt from the Help content check)");
        }

        return new self($change, HelpStatus::Missing, $change->pullRequest === null
            ? 'pushed straight to main, and its commit message neither links a Help Proposal nor says "No help changes: <reason>"'
            : 'no Help Proposal linked and no "No help changes: <reason>" in its description');
    }

    public static function unread(ReleasedChange $change, string $why): self
    {
        return new self($change, HelpStatus::Unread, "its help status couldn't be read: {$why}");
    }

    /** "OK       #275 Walk a zone: Help Proposal gpl-ajackson/firstmilemanager-help#81, merged" */
    public function line(): string
    {
        return str_pad($this->status->value, 9)."{$this->change->name()} {$this->change->title}: {$this->why}";
    }
}

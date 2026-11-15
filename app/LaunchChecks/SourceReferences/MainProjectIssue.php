<?php

namespace App\LaunchChecks\SourceReferences;

/** An issue of the main project on GitHub, as a Source Reference (fmm#n) cites it. */
final readonly class MainProjectIssue
{
    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        /** Closed as completed: for a spec, the feature it specifies is built. */
        public bool $closedAsCompleted,
        /** GitHub serves pull requests as issues too; one is never a spec. */
        public bool $pullRequest,
    ) {}

    public function reference(): string
    {
        return 'fmm#'.$this->number;
    }

    /**
     * A spec (written by /to-spec, titled "Spec: ...") lists the Articles it affects in a Help
     * content section; other issues are cited one way. An issue with a Help content section is
     * taken as a spec whatever its title.
     */
    public function isSpec(): bool
    {
        return ! $this->pullRequest
            && (preg_match('/\Aspec\s*:/i', $this->title) === 1 || HelpContentSection::of($this->body) !== null);
    }
}

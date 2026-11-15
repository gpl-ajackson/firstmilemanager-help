<?php

namespace App\LaunchChecks\SourceReferences;

/**
 * A plan, ADR or issue of the main project that an Article cites, as found at the ref under
 * test. Plans and specs list the Articles they affect back, in their Help content section;
 * ADRs and other issues are cited one way.
 */
final class CitedSource
{
    /** Whether a Published Article cites it, so a plan describes something built. */
    public bool $citedByPublished = false;

    public function __construct(
        public readonly string $reference,
        /** Where findings in it are named: "fmm:<path>" for a file, "fmm#n" for an issue. */
        public readonly string $where,
        /** Whether it lists the Articles citing it back: a plan or a spec. */
        public readonly bool $listsBack,
        public readonly ?HelpContentSection $helpContent = null,
        /** A spec closed as completed; a plan counts as built when a Published Article cites it. */
        private readonly bool $closedAsCompleted = false,
        /** Whether line numbers in it are a file's lines (an issue's body has none to open). */
        public readonly bool $hasLines = true,
    ) {}

    /** A built feature's plan or spec, whose Help content may no longer list new: Articles at launch. */
    public function isBuilt(): bool
    {
        return $this->hasLines ? $this->citedByPublished : $this->closedAsCompleted;
    }
}

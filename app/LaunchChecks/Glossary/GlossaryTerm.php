<?php

namespace App\LaunchChecks\Glossary;

/** One term of the main project's GLOSSARY.md: its name, its definition and its avoid words. */
final readonly class GlossaryTerm
{
    /** @param  list<string>  $avoid */
    public function __construct(
        public string $name,
        public string $definition,
        public array $avoid,
        public int $line,
    ) {}

    /**
     * A short fingerprint of the definition, kept in the Term Status list when the term's
     * entries were last checked against it: the first 12 hex digits of the SHA-256 of the
     * definition, its lines joined and runs of spaces made one. A change of wording or meaning
     * changes it; rewrapping the lines doesn't.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', trim((string) preg_replace('/\s+/u', ' ', $this->definition))), 0, 12);
    }
}

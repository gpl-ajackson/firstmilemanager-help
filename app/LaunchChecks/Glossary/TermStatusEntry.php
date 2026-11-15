<?php

namespace App\LaunchChecks\Glossary;

/** One term's line in the Term Status list. */
final readonly class TermStatusEntry
{
    public function __construct(
        public string $term,
        public TermStatus $status,
        /** The Product Area whose ticket writes its Glossary Entry and whose Articles first use it. */
        public string $productArea,
        /** A Published term whose entries say less than GLOSSARY.md, while the rest isn't built. */
        public bool $ahead,
        /** GlossaryTerm::fingerprint() of the definition its entries were last checked against. */
        public string $fingerprint,
        public int $line,
    ) {}
}

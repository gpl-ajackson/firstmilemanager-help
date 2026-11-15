<?php

namespace App\Renderer\Links;

/** The Article a link is written on, which decides where its links may lead. */
final readonly class LinkingPage
{
    public function __construct(
        /** Its Article Name, for links to its own Sections. */
        public string $name,
        /** A Client-tier page links only to Client-tier Articles and the Client Glossary. */
        public bool $clientTier,
        /** A Draft may link to other Drafts, which a Help Preview shows beside it. */
        public bool $draft,
    ) {}
}

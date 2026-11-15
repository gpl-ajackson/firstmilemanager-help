<?php

namespace App\LaunchChecks\Findability;

/**
 * One question of the Findability Set, in a reader's own words, with the answer search must
 * show for it in its top three results: an Article (`/articles/<name>`), one of its Sections
 * (`/articles/<name>#<Section Name>`) or a Glossary Entry (`/glossary/<term>`,
 * `/client-glossary/<term>`), named by the address a reader opens.
 */
final readonly class NamedAnswer
{
    public function __construct(
        public string $question,
        /** The answer's address, as written. */
        public string $answer,
        /** 'articles', 'glossary' or 'client-glossary'. */
        public string $kind,
        /** The Article Name, or the term's name in its address. */
        public string $name,
        /** The Section Name, for an answer that is an Article's Section. */
        public ?string $section,
        /** The question's line in findability-set.yaml. */
        public int $line,
    ) {}

    public function isArticle(): bool
    {
        return $this->kind === 'articles';
    }
}

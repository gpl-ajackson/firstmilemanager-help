<?php

namespace App\Search;

/** What one Search found, best first. */
final readonly class Results
{
    /**
     * @param  list<Result>  $results
     * @param  list<GlossaryAnswer>  $glossaryAnswers
     */
    public function __construct(
        /** The words searched for, trimmed. */
        public string $words,
        public Scope $scope,
        public array $results,
        /**
         * Glossary Entries whose term or Other Name the words are, shown above the results and
         * taking the first places.
         */
        public array $glossaryAnswers = [],
        /**
         * The Search's id once it is settled (SearchService::settle()), which its result links
         * carry to the "opened" address; null for words not settled on, such as suggestions.
         */
        public ?string $search = null,
    ) {}

    /** Whether a Glossary Entry was among the results shown, for the Search Record. */
    public function showsGlossaryEntry(): bool
    {
        return $this->glossaryAnswers !== [];
    }

    /**
     * These results as Search $search: each result's and Glossary answer's link goes through
     * its "opened" address.
     */
    public function settledAs(string $search): self
    {
        return new self(
            $this->words,
            $this->scope,
            array_map(fn (Result $result): Result => $result->openedThrough($search), $this->results),
            array_map(fn (GlossaryAnswer $answer): GlossaryAnswer => $answer->openedThrough($search), $this->glossaryAnswers),
            $search,
        );
    }

    /** Nothing found: no result and no Glossary answer. */
    public function isEmpty(): bool
    {
        return $this->results === [] && $this->glossaryAnswers === [];
    }

    /** How many results were shown, Glossary answers included. */
    public function count(): int
    {
        return count($this->glossaryAnswers) + count($this->results);
    }
}

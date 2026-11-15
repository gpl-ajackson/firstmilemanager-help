<?php

namespace App\Search;

use App\Content\AudienceTier;

/**
 * A Glossary Entry shown on top of a Search as a short answer (spec #30, user stories 51 and
 * 52): the reader typed its term, or one of its Other Names, which it then names
 * ("Also called bin. In Stockist this is a Location.").
 */
final readonly class GlossaryAnswer
{
    public function __construct(
        /** Its place among what the Search shows, from 1: Glossary answers come first. */
        public int $place,
        public string $term,
        /**
         * The answer's link. On a settled Search it is the "opened" address, which marks the
         * Search Opened and sends the reader on to the entry address; otherwise the entry address.
         */
        public string $address,
        /** The entry's own address: /glossary/<name>, or /client-glossary/<name> for a Client Glossary entry. */
        public string $entryAddress,
        public AudienceTier $audienceTier,
        /** The entry's one or two plain sentences, rendered by the content rebuild. */
        public string $definitionHtml,
        /** The Other Name the reader typed, as the entry writes it; null when they typed the term. */
        public ?string $alsoCalled = null,
    ) {}

    /** This answer with its link going through the "opened" address of a settled Search, as Result's does. */
    public function openedThrough(string $search): self
    {
        return new self(
            place: $this->place,
            term: $this->term,
            address: "/searches/{$search}/opened/{$this->place}".$this->entryAddress,
            entryAddress: $this->entryAddress,
            audienceTier: $this->audienceTier,
            definitionHtml: $this->definitionHtml,
            alsoCalled: $this->alsoCalled,
        );
    }

    /** A Client Glossary entry: written for clients, so it says "your 3PL", never Stockist. */
    public function isForClients(): bool
    {
        return $this->audienceTier === AudienceTier::Client;
    }

    /** "a" or "an" before the term, by its first letter. */
    public function article(): string
    {
        return in_array(mb_strtolower(mb_substr($this->term, 0, 1)), ['a', 'e', 'i', 'o'], true) ? 'an' : 'a';
    }
}

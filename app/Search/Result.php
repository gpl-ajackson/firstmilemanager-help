<?php

namespace App\Search;

use App\Content\ArticleType;
use App\Content\AudienceTier;

/** One result of a Search, as a reader sees it in the list. */
final readonly class Result
{
    public function __construct(
        /** Its place in the list, from 1. */
        public int $place,
        public string $articleName,
        public string $title,
        public string $summary,
        public ArticleType $type,
        public string $productArea,
        public AudienceTier $audienceTier,
        /**
         * The result's link. On a settled Search it is the "opened" address, which marks the
         * Search Opened and sends the reader on to the article address; otherwise (suggestions)
         * the article address itself.
         */
        public string $address,
        /** Where the result opens: the Article, at the matched Section when a Section heading matched. */
        public string $articleAddress,
        /** The matched Section's heading words, when the result opens at a Section. */
        public ?string $sectionHeading = null,
    ) {}

    /**
     * This result with its link going through the "opened" address of a settled Search:
     * `/searches/<Search id>/opened/<place>/articles/<name>#<Section Name>`. The browser keeps
     * the #fragment when it is sent on to the Article, so nothing is put on the Article's own
     * address and the Article still opens at its Section.
     */
    public function openedThrough(string $search): self
    {
        return new self(
            place: $this->place,
            articleName: $this->articleName,
            title: $this->title,
            summary: $this->summary,
            type: $this->type,
            productArea: $this->productArea,
            audienceTier: $this->audienceTier,
            address: "/searches/{$search}/opened/{$this->place}".$this->articleAddress,
            articleAddress: $this->articleAddress,
            sectionHeading: $this->sectionHeading,
        );
    }

    /** Client-tier results carry a "For your clients" label. */
    public function isForClients(): bool
    {
        return $this->audienceTier === AudienceTier::Client;
    }
}

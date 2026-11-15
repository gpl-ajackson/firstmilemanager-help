<?php

namespace App\Search;

/**
 * Whose search this is, set by the caller and never by the reader (spec #30, "Search"). It
 * decides which results may appear and how they are ordered, and names where a Search was
 * made for its Search Record.
 *
 * SR1 has one scope, Stockist's centre: Articles of both tiers, Client-tier results below
 * staff ones. Later scopes join here as named constructors, so callers never build engine
 * filters themselves: the app at SR3 (the reader's Staff Roles first) and a 3PL's branded
 * centre at SR4 (Client tier and that 3PL's own pages only).
 */
final readonly class Scope
{
    /**
     * @param  list<non-empty-string>  $sort  Meilisearch sort rules, applied before relevance.
     */
    private function __construct(
        public string $where,
        private ?string $filter,
        private array $sort,
    ) {}

    /** The public help centre at help.firstmilemanager.co.uk. */
    public static function helpCentre(): self
    {
        return new self(where: 'help centre', filter: null, sort: ['tier_rank:asc']);
    }

    /** @internal The engine filter for this scope; only the search service reads it. */
    public function filter(): ?string
    {
        return $this->filter;
    }

    /**
     * @internal The sort applied before relevance; only the search service reads it.
     *
     * @return list<non-empty-string>
     */
    public function sort(): array
    {
        return $this->sort;
    }
}

<?php

namespace App\Search;

use RuntimeException;
use Throwable;

/**
 * Meilisearch couldn't be reached or refused the request. A rebuild then changes nothing and
 * the search page says search isn't working; neither ever falls back to another way of searching.
 */
final class SearchUnavailable extends RuntimeException
{
    public static function at(string $host, Throwable $cause): self
    {
        return new self(
            "Meilisearch at {$host} can't be used: {$cause->getMessage()}. "
            .'Start Meilisearch, or set MEILISEARCH_HOST and MEILISEARCH_KEY to a running instance.',
            previous: $cause,
        );
    }

    public static function because(string $host, string $reason): self
    {
        return new self("Meilisearch at {$host} can't be used: {$reason}.");
    }
}

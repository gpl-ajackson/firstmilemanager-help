<?php

namespace App\Search;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Contracts\SearchQuery;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\TimeOutException;

/**
 * @internal The search service's one way into Meilisearch (config help.search). Nothing
 * outside App\Search talks to the engine.
 */
final class Engine
{
    /** How long a rebuild waits for Meilisearch to finish each step. */
    private const TASK_TIMEOUT_MS = 60_000;

    private readonly string $host;

    private readonly string $index;

    private readonly Client $client;

    /** @param  ?string  $index  The index to use; the configured one (help.search.index) when null. */
    public function __construct(?string $index = null)
    {
        $this->host = Config::string('help.search.host');
        $this->index = $index ?? Config::string('help.search.index');
        $key = Config::get('help.search.key');
        $this->client = new Client($this->host, is_string($key) && $key !== '' ? $key : null);
    }

    /**
     * An engine on a new index of its own, named after the configured one with a random ending,
     * so what is indexed there never reaches the help centre's readers. drop() deletes it.
     */
    public function fresh(): self
    {
        return new self($this->index.'_fresh_'.Str::lower(Str::random(12)));
    }

    /**
     * Deletes this engine's index and any half-built one beside it. Quietly does nothing when
     * Meilisearch can't be reached: an index left behind holds only published help.
     */
    public function drop(): void
    {
        try {
            foreach ([$this->index, $this->index.'_next'] as $index) {
                $this->wait($this->client->deleteIndex($index), allow: 'index_not_found');
            }
        } catch (CommunicationException|ApiException|TimeOutException|SearchUnavailable) {
            // Nothing a reader searches is affected.
        }
    }

    /**
     * Replaces everything in the index with $documents under $settings. The documents go into
     * a fresh index that is swapped in once complete, so a search never meets a half-built one.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $documents
     *
     * @throws SearchUnavailable
     */
    public function replace(array $settings, array $documents): void
    {
        $next = $this->index.'_next';

        $this->attempt(function () use ($next, $settings, $documents): void {
            // Either may fail harmlessly: the live index already exists, or no earlier build was left over.
            $this->wait($this->client->createIndex($this->index, ['primaryKey' => 'id']), allow: 'index_already_exists');
            $this->wait($this->client->deleteIndex($next), allow: 'index_not_found');

            $this->wait($this->client->createIndex($next, ['primaryKey' => 'id']));
            $this->wait($this->client->index($next)->updateSettings($settings));
            if ($documents !== []) {
                $this->wait($this->client->index($next)->addDocuments($documents));
            }
            $this->wait($this->client->swapIndexes([[$this->index, $next]]));
            $this->wait($this->client->deleteIndex($next));
        });
    }

    /**
     * Several searches in one request, each a list of hits, best first, in the order asked.
     *
     * @param  list<array{q: string, filter?: list<non-empty-string>, sort?: list<non-empty-string>, limit?: non-negative-int, showMatchesPosition?: bool, attributesToSearchOn?: non-empty-list<non-empty-string>, attributesToRetrieve?: non-empty-list<non-empty-string>, matchingStrategy?: 'all'|'frequency'|'last'}>  $queries
     * @return list<list<array<string, mixed>>>
     *
     * @throws SearchUnavailable
     */
    public function searchMany(array $queries): array
    {
        return $this->attempt(function () use ($queries): array {
            $built = [];
            foreach ($queries as $options) {
                $query = (new SearchQuery)->setIndexUid($this->index)->setQuery($options['q']);
                if (isset($options['filter'])) {
                    $query->setFilter($options['filter']);
                }
                if (isset($options['sort'])) {
                    $query->setSort($options['sort']);
                }
                if (isset($options['limit'])) {
                    $query->setLimit($options['limit']);
                }
                if (isset($options['showMatchesPosition'])) {
                    $query->setShowMatchesPosition($options['showMatchesPosition']);
                }
                if (isset($options['attributesToSearchOn'])) {
                    $query->setAttributesToSearchOn($options['attributesToSearchOn']);
                }
                if (isset($options['attributesToRetrieve'])) {
                    $query->setAttributesToRetrieve($options['attributesToRetrieve']);
                }
                if (isset($options['matchingStrategy'])) {
                    $query->setMatchingStrategy($options['matchingStrategy']);
                }
                $built[] = $query;
            }

            /** @var array{results: list<array{hits: list<array<string, mixed>>}>} $raw */
            $raw = $this->client->multiSearch($built);

            return array_map(fn (array $result): array => $result['hits'], $raw['results']);
        });
    }

    /**
     * The documents with these ids, holding only $fields, in no particular order.
     *
     * @param  non-empty-list<non-empty-string>  $ids
     * @param  non-empty-list<non-empty-string>  $fields
     * @return list<array<string, mixed>>
     *
     * @throws SearchUnavailable
     */
    public function documents(array $ids, array $fields): array
    {
        return $this->attempt(function () use ($ids, $fields): array {
            $found = $this->client->index($this->index)->getDocuments(
                (new DocumentsQuery)->setIds($ids)->setFields($fields)->setLimit(count($ids)),
            );

            /** @var list<array<string, mixed>> */
            return array_values($found->getResults());
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     *
     * @throws SearchUnavailable
     */
    private function attempt(callable $call): mixed
    {
        try {
            return $call();
        } catch (CommunicationException|ApiException|TimeOutException $failure) {
            throw SearchUnavailable::at($this->host, $failure);
        }
    }

    /**
     * Waits for a Meilisearch task to finish, failing unless it succeeded (or failed only with
     * the $allow error code).
     *
     * @param  array<array-key, mixed>  $task
     */
    private function wait(array $task, ?string $allow = null): void
    {
        $done = $this->client->waitForTask($task['taskUid'], self::TASK_TIMEOUT_MS, 10);
        if ($done['status'] === 'succeeded') {
            return;
        }
        $error = is_array($done['error'] ?? null) ? $done['error'] : [];
        if ($allow !== null && ($error['code'] ?? null) === $allow) {
            return;
        }
        $reason = is_string($error['message'] ?? null) ? $error['message'] : 'no reason given';

        throw SearchUnavailable::because($this->host, "a {$done['type']} task {$done['status']}: {$reason}");
    }
}

<?php

namespace App\Search;

use App\Content\ContentLibrary;
use App\Records\Records;
use Illuminate\Support\Str;

/**
 * The search service (spec #30, "Search"): the only way to search the help centre, now and for
 * the app (SR3) and branded centres (SR4) later. Every search names its caller's Scope.
 *
 * The content rebuild a deploy runs calls rebuildIndex(), which indexes what the content
 * library publishes and nothing else, so the index only ever holds current help: never a
 * Draft, a Retired Article or a Release Note.
 */
final class SearchService
{
    /** Results on one page; SR1 has no paging or filters. */
    private const LIMIT = 20;

    /** Suggestions shown as the reader types (#46), Glossary answers aside. */
    private const SUGGESTIONS = 5;

    /** A last word being typed is a prefix once it has this many letters (or digits). */
    private const PREFIX_LETTERS = 3;

    /** More Troubleshooting Articles than the help centre will ever have, all checked for a pasted message. */
    private const MESSAGE_ARTICLES_LIMIT = 1000;

    /** Longer input is cut, so a pasted page can't make an unbounded query. */
    private const MAX_WORDS_LENGTH = 500;

    public function __construct(
        private readonly Engine $engine,
        private readonly ContentLibrary $library,
        private readonly Records $records,
    ) {}

    /**
     * A settled Search (spec #30, "Search"): the reader pressed Enter or Search. Searches like
     * search(), gives the Search a new random id and records it through Records, which keeps
     * nothing until going public and leaves out obvious bots. Each result's link then goes
     * through the "opened" address with that id and the result's place.
     *
     * $search is the id of a Search the script already settled with the same words
     * (settleSuggestions()) before the form was sent: that Search is reused, so it isn't
     * recorded twice, and now holds the results shown here. Only a Search from today is reused;
     * a results page reloaded on a later day (a bookmark) is a new Search, so a past day's
     * record never changes. Blank words find nothing and record nothing.
     *
     * @throws SearchUnavailable
     */
    public function settle(string $words, Scope $scope, ?string $userAgent, ?string $search = null): Results
    {
        $found = $this->search($words, $scope);
        if ($found->words !== '' && $search !== null && Str::isUuid($search, 4) && $this->records->hasSearch($search, $found->words)) {
            $this->records->searchShownAgain($search, $found->count(), $found->showsGlossaryEntry());

            return $found->settledAs($search);
        }

        return $this->record($found, $userAgent);
    }

    /**
     * The search box's script settles a Search (#46) when the reader stops typing for two
     * seconds with three letters or more, or opens a suggestion: the words are searched as
     * suggest() searched them, so the Search holds the suggestions the reader was shown, and
     * it is recorded like settle()'s.
     *
     * @throws SearchUnavailable
     */
    public function settleSuggestions(string $words, Scope $scope, ?string $userAgent): Results
    {
        return $this->record($this->suggest($words, $scope), $userAgent);
    }

    /**
     * A result opened from a settled Search, through its "opened" address: marks that Search
     * Opened with the result's place (the first result opened counts). $address is the result's
     * own address without any Section, such as `/articles/<name>`. An unknown Search, or one
     * made before going public, changes nothing; the reader is sent on all the same.
     */
    public function opened(string $search, int $place, string $address, ?string $userAgent): void
    {
        $this->records->searchOpened($search, $place, $address, $userAgent);
    }

    /**
     * Searches within $scope, best result first. Blank words find nothing. Never recorded:
     * see settle(). Every word must be a whole word (or a typo of one): "PO" never finds
     * "position".
     *
     * A Troubleshooting Article quoting a message the words hold comes first. A Glossary Entry
     * whose term or Other Name the words are is a Glossary answer, shown above the results.
     *
     * @throws SearchUnavailable
     */
    public function search(string $words, Scope $scope): Results
    {
        return $this->find($words, $scope, asTyped: false, limit: self::LIMIT);
    }

    /**
     * Suggestions for words the reader is still typing (#46): like search(), but the last word,
     * once it has three letters or more and no space after it, may be the start of a longer
     * word ("quaran" finds Quarantine), and only the best few Articles are given. Glossary
     * answers still need the whole term or Other Name. Never recorded; settleSuggestions()
     * records them once the reader settles on them.
     *
     * @throws SearchUnavailable
     */
    public function suggest(string $words, Scope $scope): Results
    {
        return $this->find($words, $scope, asTyped: true, limit: self::SUGGESTIONS);
    }

    /** Records $found as a new Search, unless its words are blank. */
    private function record(Results $found, ?string $userAgent): Results
    {
        if ($found->words === '') {
            return $found;
        }
        $search = (string) Str::uuid();
        $this->records->recordSearch($search, $found->words, $found->count(), $found->showsGlossaryEntry(), $found->scope->where, $userAgent);

        return $found->settledAs($search);
    }

    /**
     * @param  positive-int  $limit  How many Articles at most.
     *
     * @throws SearchUnavailable
     */
    private function find(string $words, Scope $scope, bool $asTyped, int $limit): Results
    {
        $typed = mb_substr($words, 0, self::MAX_WORDS_LENGTH);
        $words = trim($typed);
        if ($words === '') {
            return new Results($words, $scope, []);
        }

        // The index keeps prefixes, but Meilisearch treats only the last word as one, and none
        // when the words end with a space; so a space is added unless the reader is still typing
        // a last word of three letters or more.
        $lastWord = (string) preg_replace('/^.*\s/su', '', $words);
        $prefix = $asTyped
            && preg_match('/\s$/u', $typed) !== 1
            && preg_match_all('/[\p{L}\p{N}]/u', $lastWord) >= self::PREFIX_LETTERS;

        $glossaryWords = GlossaryDocument::query($words);
        $queries = [
            [
                'q' => $prefix ? $words : $words.' ',
                'filter' => $this->filter($scope, 'kind = article'),
                'sort' => $scope->sort(),
                'limit' => $limit,
                'attributesToSearchOn' => IndexSettings::ARTICLE_ATTRIBUTES,
                'showMatchesPosition' => true,
            ],
            // Every Troubleshooting Article in scope, only its messages, to match the words against.
            [
                'q' => '',
                'filter' => $this->filter($scope, 'kind = article', 'has_messages = true'),
                'sort' => $scope->sort(),
                'limit' => self::MESSAGE_ARTICLES_LIMIT,
                'attributesToRetrieve' => ['name', 'message_patterns'],
            ],
        ];
        if ($glossaryWords !== '') {
            // Glossary Entries whose term or Other Names the words may be.
            $queries[] = [
                'q' => $glossaryWords.' ',
                'filter' => $this->filter($scope, 'kind = glossary'),
                'sort' => $scope->sort(),
                'limit' => self::LIMIT,
                'attributesToSearchOn' => IndexSettings::GLOSSARY_ATTRIBUTES,
                'matchingStrategy' => 'all',
            ];
        }
        [$hits, $messageHits, $entryHits] = [...$this->engine->searchMany($queries), []];

        // A Troubleshooting Article quoting the pasted message comes first, then the rest as ranked.
        $firstNames = array_values(array_map(self::nameOf(...), array_filter($messageHits, fn (array $hit): bool => $this->quotes($hit, $words))));
        $first = $this->articleHits($firstNames, $hits);
        $rest = array_filter($hits, fn (array $hit): bool => ! in_array($hit['name'] ?? null, $firstNames, true));

        $answers = [];
        foreach ($entryHits as $hit) {
            $answer = GlossaryDocument::answer($hit, $words, count($answers) + 1);
            if ($answer !== null) {
                $answers[] = $answer;
            }
        }

        $results = [];
        foreach (array_slice([...$first, ...$rest], 0, $limit) as $hit) {
            $results[] = ArticleDocument::result($hit, count($answers) + count($results) + 1);
        }

        return new Results($words, $scope, $results, glossaryAnswers: $answers);
    }

    /**
     * The hits for these Articles, in this order, as result() reads them: taken from the ranked
     * $hits when there (without their matches, so they open at the top, as a pasted message's
     * Article always has), else fetched, in one request, only when an Article quoting a pasted
     * message wasn't among them.
     *
     * @param  list<string>  $names
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     *
     * @throws SearchUnavailable
     */
    private function articleHits(array $names, array $hits): array
    {
        if ($names === []) {
            return [];
        }
        $byName = [];
        foreach ($hits as $hit) {
            unset($hit['_matchesPosition']);
            $byName[self::nameOf($hit)] = $hit;
        }
        $missing = [];
        foreach ($names as $name) {
            if (! isset($byName[$name])) {
                $missing[] = 'article-'.$name;
            }
        }
        if ($missing !== []) {
            foreach ($this->engine->documents($missing, ArticleDocument::RESULT_FIELDS) as $document) {
                $byName[self::nameOf($document)] = $document;
            }
        }

        $found = [];
        foreach ($names as $name) {
            if (isset($byName[$name])) {
                $found[] = $byName[$name];
            }
        }

        return $found;
    }

    /**
     * An Article hit's name.
     *
     * @param  array<string, mixed>  $hit
     */
    private static function nameOf(array $hit): string
    {
        return is_string($hit['name'] ?? null) ? $hit['name'] : '';
    }

    /**
     * Replaces the whole index with the content library's Published Articles and Glossary
     * Entries of both tiers, as the content rebuild has just written them, and loads every Other
     * Name as a one-way synonym of its term. Readers keep searching the old index until the new one is
     * complete.
     *
     * @throws SearchUnavailable
     */
    public function rebuildIndex(): void
    {
        $entries = $this->library->glossaryEntries();
        $documents = [
            ...$this->library->publishedArticles()->map(ArticleDocument::of(...))->all(),
            ...$entries->map(GlossaryDocument::of(...))->all(),
        ];

        $this->engine->replace(IndexSettings::with(GlossaryDocument::synonyms($entries)), $documents);
    }

    /**
     * Runs $use with a search service of its own, over a fresh index holding what the content
     * library publishes now (as rebuildIndex() fills the help centre's index), then deletes that
     * index. The help centre's own index and its readers never see it. The Findability check
     * (Launch Check 10) searches a pull request's own content this way.
     *
     * @template T
     *
     * @param  callable(SearchService): T  $use
     * @return T
     *
     * @throws SearchUnavailable
     */
    public function inFreshIndex(callable $use): mixed
    {
        $engine = $this->engine->fresh();
        $service = new self($engine, $this->library, $this->records);
        try {
            $service->rebuildIndex();

            return $use($service);
        } finally {
            $engine->drop();
        }
    }

    /**
     * The engine filter: the scope's own, and $conditions.
     *
     * @param  non-empty-string  ...$conditions
     * @return list<non-empty-string>
     */
    private function filter(Scope $scope, string ...$conditions): array
    {
        $filter = array_values($conditions);
        $own = $scope->filter();
        if ($own !== null && $own !== '') {
            $filter[] = $own;
        }

        return $filter;
    }

    /**
     * Whether an Article's hit quotes a message that $words hold, with any words in its Placeholders.
     *
     * @param  array<string, mixed>  $hit
     */
    private function quotes(array $hit, string $words): bool
    {
        $patterns = $hit['message_patterns'] ?? [];
        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (is_string($pattern) && MessagePattern::matches($pattern, $words)) {
                return true;
            }
        }

        return false;
    }
}

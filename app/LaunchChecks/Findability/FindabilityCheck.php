<?php

namespace App\LaunchChecks\Findability;

use App\Content\ContentLibrary;
use App\Content\InvalidContent;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\Renderer\Links\LinkTargets;
use App\Search\Results;
use App\Search\Scope;
use App\Search\SearchService;
use App\Search\SearchUnavailable;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Launch Check 10, Findability (spec #30, user stories 122 and 170): every question in the
 * Findability Set shows its named answer in the top three results on Stockist's centre,
 * searched against the content under test, not the help centre's live index.
 *
 * The content is loaded into the database inside a transaction that is always rolled back,
 * and indexed into a fresh Meilisearch index that is deleted afterwards, so the check needs the
 * help centre's database and Meilisearch, as a deploy does, and changes neither.
 */
final readonly class FindabilityCheck implements Check
{
    /** "The top three results" (spec #30, Launch Check 10). */
    public const TOP = 3;

    public function __construct(
        private ContentLibrary $library,
        private SearchService $search,
        private DatabaseManager $database,
    ) {}

    public function run(CheckContext $context, Findings $findings): void
    {
        $set = FindabilitySet::read($context->contentPath, $findings);
        if ($set->isEmpty) {
            $message = $set->exists ? 'lists no questions' : 'there is no Findability Set: write '.FindabilitySet::FILE.' in the content folder, each reader\'s question with its answer';
            $context->atLaunch
                ? $findings->fail('findability.set', FindabilitySet::FILE, null, $message)
                : $findings->notRun("Findability: {$message} (this fails at launch)");

            return;
        }

        $targets = $context->content()->linkTargets;
        if ($targets === null) {
            $findings->notRun('Findability: the content folder can\'t be rebuilt, so it can\'t be searched (see the header failures)');

            return;
        }

        $renamed = $this->formerNames($context);
        $searchable = [];
        foreach ($set->questions as $question) {
            $problem = $this->problemWith($question, $targets, $renamed);
            $problem === null
                ? $searchable[] = $question
                : $findings->fail('findability.answer', FindabilitySet::FILE, $question->line, "\"{$question->question}\" names {$question->answer}, but {$problem}");
        }
        if ($searchable === []) {
            return;
        }

        try {
            $found = $this->searchAll($context, $searchable);
        } catch (SearchUnavailable $unavailable) {
            $findings->fail('findability.search', FindabilitySet::FILE, null, "the questions couldn't be searched: {$unavailable->getMessage()}");

            return;
        } catch (InvalidContent) {
            $findings->notRun('Findability: the content folder can\'t be rebuilt, so it can\'t be searched (see the header failures)');

            return;
        } catch (Throwable $failure) {
            $findings->fail('findability.search', FindabilitySet::FILE, null,
                "the content couldn't be loaded into the help centre's database to search it: {$failure->getMessage()}");

            return;
        }

        foreach ($searchable as $index => $question) {
            $miss = $this->miss($question, $found[$index]);
            if ($miss !== null) {
                $findings->fail('findability.top-three', FindabilitySet::FILE, $question->line,
                    "\"{$question->question}\" should show {$question->answer} in the top three results, but {$miss}");
            }
        }
    }

    /**
     * Each question's results on Stockist's centre, searched in a fresh index of the content
     * under test. The database copy is rebuilt from that content in a transaction rolled back
     * afterwards, so the help centre's own copy is left as it was.
     *
     * @param  list<NamedAnswer>  $questions
     * @return list<Results>
     */
    private function searchAll(CheckContext $context, array $questions): array
    {
        $connection = $this->database->connection();
        $connection->beginTransaction();
        try {
            // As readers will search it: an Article waiting for the demo is searched as if it were there.
            $this->library->rebuild($context->contentPath, $context->picturesPath, everyFeatureOnDemo: true);

            return $this->search->inFreshIndex(fn (SearchService $search): array => array_map(
                fn (NamedAnswer $question): Results => $search->search($question->question, Scope::helpCentre()),
                $questions,
            ));
        } finally {
            $connection->rollBack();
        }
    }

    /** Why the results don't show the question's answer in the top three, or null when they do. */
    private function miss(NamedAnswer $question, Results $results): ?string
    {
        $shown = $this->shown($results);
        $top = array_slice($shown, 0, self::TOP);
        $showed = $top === [] ? 'search found nothing' : 'search showed '.implode(', ', $top).' first';

        $wanted = $question->isArticle() ? "/articles/{$question->name}" : $question->answer;
        foreach ($shown as $index => $address) {
            if ($address !== $wanted && ! str_starts_with($address, $wanted.'#')) {
                continue;
            }
            $place = self::ordinal($index + 1);
            if ($question->section !== null && $address !== $question->answer) {
                return "it came {$place} as {$address}, which doesn't open at that Section ({$showed})";
            }
            if ($index < self::TOP) {
                return null;
            }

            return "it came {$place} ({$showed})";
        }

        return "it isn't among the results ({$showed})";
    }

    /**
     * Where each result opens, in the order a reader sees them: Glossary answers, then Articles.
     *
     * @return list<string>
     */
    private function shown(Results $results): array
    {
        return [
            ...array_map(fn ($answer): string => $answer->entryAddress, $results->glossaryAnswers),
            ...array_map(fn ($result): string => $result->articleAddress, $results->results),
        ];
    }

    /**
     * Why search can't show this answer at all, or null when it can.
     *
     * @param  array<string, string>  $renamed  Former Article Name => the Article's name now.
     */
    private function problemWith(NamedAnswer $question, LinkTargets $targets, array $renamed): ?string
    {
        if (! $question->isArticle()) {
            return $targets->findTerm($question->name, $question->kind === 'client-glossary') !== null ? null
                : ($question->kind === 'glossary'
                    ? "there is no Glossary Entry for \"{$question->name}\""
                    : "there is no Client Glossary entry for \"{$question->name}\"");
        }

        $article = $targets->findArticle($question->name);
        if ($article === null) {
            return isset($renamed[$question->name])
                ? "\"{$question->name}\" is now /articles/{$renamed[$question->name]}: name it by its name now"
                : "there is no Article named \"{$question->name}\"";
        }
        if ($article['status'] !== 'Published' && ! $article['waitingForDemo']) {
            return "\"{$question->name}\" is {$article['status']}, and search shows only Published Articles";
        }
        if ($article['jointOnly']) {
            return "\"{$question->name}\" is Joint-only, and search never shows a Joint-only Article";
        }
        if ($question->section !== null && ! array_key_exists($question->section, $article['sections'])) {
            return "\"{$question->name}\" has no Section named \"{$question->section}\"";
        }

        return null;
    }

    /** @return array<string, string> Each former Article Name (from former_addresses) => the Article's name now. */
    private function formerNames(CheckContext $context): array
    {
        $renamed = [];
        foreach ($context->articles() as $article) {
            foreach ((array) ($article->header['former_addresses'] ?? []) as $former) {
                if (is_string($former) && str_starts_with($former, '/articles/')) {
                    $renamed[substr($former, strlen('/articles/'))] = $article->name;
                }
            }
        }

        return $renamed;
    }

    /** 1st, 2nd, 3rd, 4th, ... 11th, 12th, 13th, ... 21st. */
    private static function ordinal(int $place): string
    {
        $suffix = match (true) {
            in_array($place % 100, [11, 12, 13], true) => 'th',
            $place % 10 === 1 => 'st',
            $place % 10 === 2 => 'nd',
            $place % 10 === 3 => 'rd',
            default => 'th',
        };

        return $place.$suffix;
    }
}

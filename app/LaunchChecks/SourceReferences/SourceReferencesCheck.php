<?php

namespace App\LaunchChecks\SourceReferences;

use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\Renderer\Links\LinkTargets;

/**
 * Launch Check 2, Source References (plan S7 step 1; the main project's
 * docs/agents/help-content.md): both halves of every Source Reference agree, read from the main
 * project at the ref under test (main, or the paired change).
 *
 * - Every plan path, ADR-nnnn and fmm#n an Article cites exists (sources.exists).
 * - Every cited plan or spec lists the Article back, as /articles/<name>, in its Help content
 *   section (sources.listed-back).
 * - Everything a cited plan's or spec's Help content section lists by address exists: Articles
 *   (and their Section Names), Glossary and Client Glossary entries (sources.listed).
 * - A built feature's plan or spec no longer lists a `new:` Article (sources.new-article): a
 *   report before launch, a failure at launch (`--at-launch`, or once going public is on).
 *
 * A Retired Article's references are history and aren't checked; a Draft's are.
 */
final readonly class SourceReferencesCheck implements Check
{
    /** Where the main project keeps its plans, which list Articles back. */
    public const PLANS = '.junie/plans/';

    public function run(CheckContext $context, Findings $findings): void
    {
        /** @var array<string, CitedSource|string|null> $sources Each reference once: what it is, why it's missing, or null when it can't be checked. */
        $sources = [];
        $unchecked = [];

        foreach ($context->articles() as $article) {
            $references = $article->header['source_references'] ?? null;
            if (! is_array($references) || $this->isRetired($article) || $context->refuses($article->file, 'source_references')) {
                continue;
            }

            foreach (array_unique(array_filter($references, is_string(...))) as $reference) {
                $line = $article->lineOfItem('source_references', $reference);
                if (! array_key_exists($reference, $sources)) {
                    try {
                        $sources[$reference] = $this->find($reference, $context);
                    } catch (UnreadableIssue $unreadable) {
                        $findings->fail('sources.unreadable', $article->file, $line, "\"{$reference}\" couldn't be read from GitHub ({$unreadable->getMessage()}): re-run, or check FMM_READ_TOKEN can read the issues of ".MainProjectIssues::REPOSITORY);

                        continue;
                    }
                }
                $source = $sources[$reference];

                if ($source === null) {
                    $unchecked[$reference] = true;
                } elseif (is_string($source)) {
                    $findings->fail('sources.exists', $article->file, $line, "\"{$reference}\": {$source}");
                } else {
                    $source->citedByPublished = $source->citedByPublished || ($article->header['status'] ?? null) === 'Published';
                    $this->checkListedBack($source, $article, $line, $findings);
                }
            }
        }

        $cited = array_filter($sources, fn (CitedSource|string|null $source): bool => $source instanceof CitedSource && $source->helpContent !== null);
        $this->checkListed($cited, $context, $findings);

        if ($unchecked !== []) {
            $findings->notRun('Source References to the main project\'s issues ('.implode(', ', array_keys($unchecked))
                .'): there is no FMM_READ_TOKEN to read them from GitHub with');
        }
    }

    /**
     * What a reference cites, at the ref under test: the source, why it doesn't exist, or null
     * when it can't be checked here (an issue, with no key to read GitHub with).
     *
     * @throws UnreadableIssue
     */
    private function find(string $reference, CheckContext $context): CitedSource|string|null
    {
        if (preg_match('/\AADR-(\d{4})\z/', $reference, $adr) === 1) {
            return $context->mainProject->adr((int) $adr[1]) === null
                ? "the main project has no ADR numbered {$adr[1]} (docs/adr/{$adr[1]}-*.md)"
                : new CitedSource($reference, $reference, listsBack: false);
        }

        if (preg_match('/\Afmm#(\d+)\z/', $reference, $issue) === 1) {
            if ($context->issues === null) {
                return null;
            }
            $found = $context->issues->find((int) $issue[1]);
            if ($found === null) {
                return MainProjectIssues::REPOSITORY." has no issue {$issue[1]}";
            }

            return new CitedSource($reference, $reference, $found->isSpec(), HelpContentSection::of($found->body),
                closedAsCompleted: $found->closedAsCompleted, hasLines: false);
        }

        $text = $context->mainProject->read($reference);
        if ($text === null) {
            return 'the main project has no such file';
        }
        $isPlan = str_starts_with($reference, self::PLANS);

        return new CitedSource($reference, 'fmm:'.$reference, $isPlan, $isPlan ? HelpContentSection::of($text) : null);
    }

    private function checkListedBack(CitedSource $source, ArticleFile $article, int $line, Findings $findings): void
    {
        if (! $source->listsBack) {
            return;
        }
        $address = '/articles/'.$article->name;
        if ($source->helpContent === null) {
            $findings->fail('sources.listed-back', $article->file, $line,
                "\"{$source->reference}\" has no Help content section to list {$address} in");
        } elseif (! $source->helpContent->listsArticle($article->name)) {
            $findings->fail('sources.listed-back', $article->file, $line,
                "\"{$source->reference}\" doesn't list {$address} in its Help content section");
        }
    }

    /** @param  array<string, CitedSource>  $sources */
    private function checkListed(array $sources, CheckContext $context, Findings $findings): void
    {
        if ($sources === []) {
            return;
        }

        $targets = $context->content()->linkTargets;
        if ($targets === null) {
            $findings->notRun('Source References: the help that Help content sections list, because the content folder can\'t be rebuilt (see the header failures)');
        }
        $renamed = $this->formerNames($context);

        foreach ($sources as $source) {
            $helpContent = $source->helpContent;
            if ($helpContent === null) {
                continue;
            }
            foreach ($targets === null ? [] : $helpContent->addresses() as $address) {
                $problem = $this->problemWith($address, $targets, $renamed);
                if ($problem === null) {
                    continue;
                }
                $line = $source->hasLines ? $address['line'] : null;
                $message = "Help content lists \"{$address['written']}\": {$problem}";
                // Help not written yet is drafted in its own pull request, area by area, until launch.
                if ($context->atLaunch || ! $this->notWrittenYet($address, $targets, $renamed)) {
                    $findings->fail('sources.listed', $source->where, $line, $message);
                } else {
                    $findings->report('sources.listed', $source->where, $line, $message.' (this fails at launch)');
                }
            }

            if (! $source->isBuilt()) {
                continue;
            }
            foreach ($helpContent->newArticles() as $new) {
                $message = "Help content still lists new: \"{$new['title']}\" for a built feature: write the Article and list it as /articles/<name>";
                $line = $source->hasLines ? $new['line'] : null;
                $context->atLaunch
                    ? $findings->fail('sources.new-article', $source->where, $line, $message)
                    : $findings->report('sources.new-article', $source->where, $line, $message.' (this fails at launch)');
            }
        }
    }

    /**
     * Whether the address names an Article or Glossary Entry that doesn't exist under any name yet.
     *
     * @param  array{kind: string, name: string, section: ?string, written: string, line: int}  $address
     * @param  array<string, string>  $renamed  Former Article Name => the Article's name now.
     */
    private function notWrittenYet(array $address, LinkTargets $targets, array $renamed): bool
    {
        ['kind' => $kind, 'name' => $name] = $address;
        if ($kind === 'glossary' || $kind === 'client-glossary') {
            return $targets->findTerm($name, $kind === 'client-glossary') === null;
        }

        return $targets->findArticle($name) === null && ! isset($renamed[$name]);
    }

    /**
     * @param  array{kind: string, name: string, section: ?string, written: string, line: int}  $address
     * @param  array<string, string>  $renamed  Former Article Name => the Article's name now.
     */
    private function problemWith(array $address, LinkTargets $targets, array $renamed): ?string
    {
        ['kind' => $kind, 'name' => $name, 'section' => $section] = $address;

        if ($kind === 'glossary' || $kind === 'client-glossary') {
            return $targets->findTerm($name, $kind === 'client-glossary') !== null ? null
                : ($kind === 'glossary' ? "there is no Glossary Entry for \"{$name}\"" : "there is no Client Glossary entry for \"{$name}\"");
        }

        $article = $targets->findArticle($name);
        if ($article === null) {
            return isset($renamed[$name])
                ? "\"{$name}\" is now /articles/{$renamed[$name]}: list it by its name now"
                : "there is no Article named \"{$name}\"";
        }
        if ($article['status'] === 'Retired') {
            return "\"{$name}\" is Retired: list the Article that replaces it, or remove it";
        }
        if ($section !== null && ! array_key_exists($section, $article['sections'])) {
            return "\"{$name}\" has no Section named \"{$section}\"";
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

    private function isRetired(ArticleFile $article): bool
    {
        return ($article->header['status'] ?? null) === 'Retired';
    }
}

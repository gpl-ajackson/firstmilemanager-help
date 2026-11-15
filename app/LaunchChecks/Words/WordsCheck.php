<?php

namespace App\LaunchChecks\Words;

use App\LaunchChecks\ArticleBody;
use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\LaunchChecks\Glossary\MainGlossary;
use App\LaunchChecks\WordPattern;

/**
 * Launch Check 5, Words (spec #30): no Article or Glossary Entry, Drafts included, uses a
 * GLOSSARY.md avoid word or a word the Style Guide bans: whole words, any capitals, plurals
 * too. Each use fails with its file and line. Not checked:
 *
 * - a Glossary Entry's Other Names and former names (its "Also called" line) and its status
 *   values (the screen's own words);
 * - Stockist messages quoted word for word: quote blocks and a Troubleshooting Article's
 *   messages header (an avoid word in a message is fixed in the app);
 * - what a reader scans or types, written in the mono face (code spans);
 * - a use marked as naming the wrong word to say what the right term is: the word alone in
 *   quotation marks ("bin", “bin” or ‘bin’);
 * - the Content Owner's Ignored Words (avoid words only in one sense, such as "site");
 * - an avoid word that is itself a GLOSSARY.md term, or part of a longer one ("bay" in "Dock
 *   Bay"): a term is always the right word.
 */
final readonly class WordsCheck implements Check
{
    /** Header data the writer writes in their own words, by kind of file. */
    private const ARTICLE_FIELDS = ['title', 'summary', 'what_changed'];

    private const ENTRY_FIELDS = ['where_you_see_it'];

    private const QUOTES_BEFORE = ['"', '“', '‘', "'"];

    private const QUOTES_AFTER = ['"', '”', '’', "'"];

    public function run(CheckContext $context, Findings $findings): void
    {
        $glossary = $context->mainProject->glossary();
        $ignored = IgnoredWords::read($context->contentPath, $findings);
        $words = [
            ...$this->avoidWords($glossary, $ignored, $findings),
            ...StyleGuide::bannedWords($context->styleGuidePath, $findings) ?? [],
        ];
        if ($words === []) {
            return;
        }
        $terms = array_map(WordPattern::of(...), array_keys($glossary->terms ?? []));

        foreach ($context->articles() as $article) {
            foreach (self::ARTICLE_FIELDS as $field) {
                $this->checkText($article->text($field), $article->file, $article->lineOf($field), $words, $terms, $findings);
            }
            $this->checkBody($article, $words, $terms, $findings);
        }

        foreach ($context->glossaryEntries() as $entry) {
            foreach (self::ENTRY_FIELDS as $field) {
                $this->checkText($entry->text($field), $entry->file, $entry->lineOf($field), $words, $terms, $findings);
            }
            $values = $entry->header['status_values'] ?? null;
            foreach (is_array($values) ? $values : [] as $value) {
                $meaning = is_array($value) && is_string($value['meaning'] ?? null) ? $value['meaning'] : null;
                if ($meaning !== null) {
                    $this->checkText($meaning, $entry->file, $entry->lineOfText('status_values', $meaning), $words, $terms, $findings);
                }
            }
            $this->checkBody($entry, $words, $terms, $findings);
        }
    }

    /**
     * Every GLOSSARY.md avoid word, with the terms to say instead, less the Ignored Words and
     * any that are terms themselves. Reports an Ignored Word GLOSSARY.md no longer lists.
     *
     * @return list<WrongWord>
     */
    private function avoidWords(?MainGlossary $glossary, IgnoredWords $ignored, Findings $findings): array
    {
        if ($glossary === null) {
            return []; // the Glossary Check fails a checkout with no GLOSSARY.md
        }

        $terms = [];
        foreach (array_keys($glossary->terms) as $name) {
            $terms[mb_strtolower($name)] = true;
        }

        $words = [];
        $avoided = [];
        foreach ($glossary->terms as $term) {
            foreach ($term->avoid as $word) {
                $key = mb_strtolower($word);
                $avoided[$key] = true;
                if (isset($terms[$key]) || $ignored->has($word)) {
                    continue;
                }
                $words[$key] = isset($words[$key])
                    ? $words[$key]->withSayInstead($term->name)
                    : new WrongWord($word, WrongWord::AVOID, [$term->name]);
            }
        }

        foreach ($ignored->words as $word => $line) {
            if (! isset($avoided[$word])) {
                $findings->report('words.ignored', IgnoredWords::FILE, $line, "\"{$word}\" is not an avoid word in GLOSSARY.md: take it off the Ignored Words");
            }
        }

        return array_values($words);
    }

    /**
     * @param  list<WrongWord>  $words
     * @param  list<string>  $terms
     */
    private function checkBody(ArticleFile $file, array $words, array $terms, Findings $findings): void
    {
        foreach (ArticleBody::of($file)->prose() as ['text' => $text, 'line' => $line]) {
            $this->checkText($text, $file->file, $line, $words, $terms, $findings);
        }
    }

    /**
     * Fails each use of a wrong word in a piece of the writer's text, starting on $line.
     *
     * @param  list<WrongWord>  $words
     * @param  list<string>  $terms  each GLOSSARY.md term's pattern
     */
    private function checkText(?string $text, string $file, int $line, array $words, array $terms, Findings $findings): void
    {
        if ($text === null || $text === '') {
            return;
        }

        $termSpans = [];
        foreach ($terms as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$found, $at]) {
                $termSpans[] = [$at, $at + strlen($found)];
            }
        }

        $uses = [];
        foreach ($words as $word) {
            preg_match_all($word->pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$found, $at]) {
                $end = $at + strlen($found);
                if (isset($uses["{$at}:{$end}"]) || $this->isMarked($text, $at, $end) || $this->isInLongerTerm($at, $end, $termSpans)) {
                    continue;
                }
                $uses["{$at}:{$end}"] = [$at, $word, $found];
            }
        }

        usort($uses, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        foreach ($uses as [$at, $word, $found]) {
            $findings->fail(
                "words.{$word->kind}",
                $file,
                $line + substr_count(substr($text, 0, $at), "\n"),
                $word->message((string) preg_replace('/\s+/u', ' ', $found)),
            );
        }
    }

    /** Whether the word stands alone in quotation marks, naming it as the wrong word. */
    private function isMarked(string $text, int $at, int $end): bool
    {
        $before = mb_substr(substr($text, 0, $at), -1);
        $after = mb_substr(substr($text, $end), 0, 1);

        return in_array($before, self::QUOTES_BEFORE, true) && in_array($after, self::QUOTES_AFTER, true);
    }

    /** @param  list<array{int, int}>  $termSpans */
    private function isInLongerTerm(int $at, int $end, array $termSpans): bool
    {
        foreach ($termSpans as [$termAt, $termEnd]) {
            if ($termAt <= $at && $end <= $termEnd && $termEnd - $termAt > $end - $at) {
                return true;
            }
        }

        return false;
    }
}

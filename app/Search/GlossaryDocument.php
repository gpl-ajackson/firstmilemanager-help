<?php

namespace App\Search;

use App\Content\AudienceTier;
use App\Content\GlossaryEntry;
use UnexpectedValueException;

/**
 * @internal How a Glossary Entry is indexed, how its Other Names become synonyms, and when a
 * hit is a reader's answer.
 *
 * An entry's "names" are its term and every name it is also called (GlossaryEntry::alsoCalled():
 * its Other Names and former names). Each Other Name maps one way to the term, so "bin" also
 * finds Articles about Locations, but "location" never finds a waste bin.
 *
 * An entry answers a Search only when the words are one of its names (a few question words such
 * as "what is a" aside, and with the same typo rule as the engine), so "stock" answers with the
 * Stock entry but "move stock" doesn't, and a synonym never makes a second entry answer: "bin"
 * finds the Location entry, never an entry whose own Other Name is "location".
 */
final class GlossaryDocument
{
    /** Words a reader may put round a term when asking what it is; never part of a name. */
    private const QUESTION_WORDS = ['a', 'an', 'the', 'what', 'whats', 'is', 'are', 'does', 'mean', 'means', 'meaning', 'called'];

    /**
     * Every entry's Other Names as one-way synonyms of its term. A word shared by two terms maps
     * to both.
     *
     * @param  iterable<GlossaryEntry>  $entries
     * @return array<string, list<string>>
     */
    public static function synonyms(iterable $entries): array
    {
        $synonyms = [];
        foreach ($entries as $entry) {
            $terms = array_map(mb_strtolower(...), self::termNames($entry->term));
            foreach ($entry->alsoCalled() as $name) {
                $name = mb_strtolower(trim($name));
                $mapsTo = array_values(array_diff($terms, [$name]));
                if ($mapsTo !== []) {
                    $synonyms[$name] = array_values(array_unique([...$synonyms[$name] ?? [], ...$mapsTo]));
                }
            }
        }
        ksort($synonyms);

        return $synonyms;
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(GlossaryEntry $entry): array
    {
        $termNames = self::termNames($entry->term);

        return [
            'id' => ltrim(str_replace('/', '-', $entry->address()), '-'),
            'kind' => 'glossary',
            'name' => $entry->name,
            'term' => $entry->term,
            'audience_tier' => $entry->audience_tier->value,
            'tier_rank' => $entry->audience_tier === AudienceTier::Client ? 1 : 0,
            'names' => [...$termNames, ...$entry->alsoCalled()],
            'term_names' => $termNames,
            'also_called' => $entry->alsoCalled(),
            'address' => $entry->address(),
            'definition_html' => $entry->body_html,
        ];
    }

    /**
     * The words the engine is asked for: the reader's words without question words.
     */
    public static function query(string $words): string
    {
        return implode(' ', self::words($words));
    }

    /**
     * The hit as an answer to $words, or null when the words aren't one of its names.
     *
     * @param  array<string, mixed>  $hit
     */
    public static function answer(array $hit, string $words, int $place): ?GlossaryAnswer
    {
        $typed = self::words($words);
        if ($typed === []) {
            return null;
        }

        $alsoCalled = null;
        if (! self::anyIs(self::list($hit, 'term_names'), $typed)) {
            $alsoCalled = self::first(self::list($hit, 'also_called'), $typed);
            if ($alsoCalled === null) {
                return null;
            }
        }

        return new GlossaryAnswer(
            place: $place,
            term: self::text($hit, 'term'),
            address: self::text($hit, 'address'),
            entryAddress: self::text($hit, 'address'),
            audienceTier: AudienceTier::from(self::text($hit, 'audience_tier')),
            definitionHtml: self::text($hit, 'definition_html'),
            alsoCalled: $alsoCalled,
        );
    }

    /**
     * The names a term is known by: "Advanced Shipping Notice (ASN)" is both "Advanced Shipping
     * Notice" and "ASN".
     *
     * @return list<string>
     */
    private static function termNames(string $term): array
    {
        if (preg_match('/^(.+?)\s*\(([^()]+)\)$/u', trim($term), $parts) !== 1) {
            return [trim($term)];
        }

        return [trim($parts[1]), trim($parts[2])];
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $typed
     */
    private static function anyIs(array $names, array $typed): bool
    {
        return self::first($names, $typed) !== null;
    }

    /**
     * The first of $names that the typed words are.
     *
     * @param  list<string>  $names
     * @param  list<string>  $typed
     */
    private static function first(array $names, array $typed): ?string
    {
        foreach ($names as $name) {
            $words = self::words($name);
            if (count($words) === count($typed) && array_filter(array_map(self::sameWord(...), $typed, $words), fn (bool $same): bool => ! $same) === []) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether a typed word is a name's word, allowing the engine's typos: one in a word of five
     * letters or more, two in one of nine or more.
     */
    private static function sameWord(string $typed, string $word): bool
    {
        $length = mb_strlen($typed);
        $allowed = $length >= 9 ? 2 : ($length >= 5 ? 1 : 0);

        return $typed === $word || ($allowed > 0 && levenshtein($typed, $word) <= $allowed);
    }

    /**
     * Lower-case words, without question words.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(str_replace(['’', "'"], '', $text)), flags: PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff($words === false ? [] : $words, self::QUESTION_WORDS));
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return list<string>
     */
    private static function list(array $hit, string $field): array
    {
        $value = $hit[$field] ?? null;
        if (! is_array($value)) {
            throw new UnexpectedValueException("The search index has no list \"{$field}\" for a Glossary Entry; rebuild it with help:rebuild.");
        }

        return array_values(array_filter($value, is_string(...)));
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private static function text(array $hit, string $field): string
    {
        $value = $hit[$field] ?? null;
        if (! is_string($value)) {
            throw new UnexpectedValueException("The search index has no text \"{$field}\" for a Glossary Entry; rebuild it with help:rebuild.");
        }

        return $value;
    }
}

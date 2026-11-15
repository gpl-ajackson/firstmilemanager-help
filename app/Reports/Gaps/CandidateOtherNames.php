<?php

namespace App\Reports\Gaps;

use App\Content\GlossaryEntry;
use Illuminate\Support\Collection;

/**
 * Candidate Other Names (spec #30, user story 153): No-results Searches that look like a reader's
 * word for a known term, for the Content Owner to pick as Other Names (ticket #10). A Search
 * looks like one when its words, with spaces, hyphens and capitals put aside, are a term's, an
 * Other Name's or a former name's spelt another way that search's typo rules don't match: one
 * letter out (two in a name of eight letters or more), a plural, or the same sound. Only short
 * Searches, of three words at most, are a word for something; a sentence or a pasted message is
 * not. Search already finds every name exactly, so a Search that is one is no candidate.
 */
final class CandidateOtherNames
{
    /** The most words a reader's word for a term has. */
    private const MOST_WORDS = 3;

    /**
     * @param  array<string, int>  $noResults  each No-results Search's words with how often it was made, commonest first
     * @param  Collection<int, GlossaryEntry>  $entries  every Glossary Entry, staff and client
     * @return list<array{words: string, searches: int, entry: GlossaryEntry, name: string}> commonest first; name is the term or name it looks like
     */
    public static function among(array $noResults, Collection $entries): array
    {
        $names = [];
        foreach ($entries as $entry) {
            foreach ([$entry->term, ...$entry->alsoCalled()] as $name) {
                $names[] = ['entry' => $entry, 'name' => $name];
            }
        }
        $known = array_map(fn (array $name): string => self::plain($name['name']), $names);

        $candidates = [];
        foreach ($noResults as $words => $searches) {
            $words = (string) $words;
            if (str_contains($words, '[') || count(explode(' ', self::plain($words))) > self::MOST_WORDS
                || in_array(self::plain($words), $known, true)) {
                continue;
            }
            $closest = null;
            foreach ($names as $name) {
                $distance = self::distance($words, $name['name']);
                if ($distance !== null && ($closest === null || $distance < $closest['distance'])) {
                    $closest = [...$name, 'distance' => $distance];
                }
            }
            if ($closest !== null) {
                $candidates[] = ['words' => $words, 'searches' => $searches, 'entry' => $closest['entry'], 'name' => $closest['name']];
            }
        }

        return $candidates;
    }

    /** How far the words are from the name when they look like it (0 is the same letters); null when they don't. */
    private static function distance(string $words, string $name): ?int
    {
        $typed = self::letters($words);
        $named = self::letters($name);
        if (strlen($typed) < 3 || $named === '') {
            return null;
        }
        $distance = levenshtein($typed, $named);
        $plural = in_array($typed, [$named.'s', $named.'es'], true) || in_array($named, [$typed.'s', $typed.'es'], true);
        $sound = metaphone($typed);

        return match (true) {
            $plural, $distance <= (strlen($named) >= 8 ? 2 : 1) => $distance,
            strlen($sound) >= 3 && $sound === metaphone($named) => $distance,
            default => null,
        };
    }

    /** Lower case, single spaces: how search sees the words. */
    private static function plain(string $words): string
    {
        return trim((string) preg_replace('/[\s-]+/u', ' ', mb_strtolower($words)));
    }

    /** Only the letters and digits, lower case. */
    private static function letters(string $words): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($words));
    }
}

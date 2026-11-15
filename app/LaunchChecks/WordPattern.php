<?php

namespace App\LaunchChecks;

/**
 * A word or phrase as the Glossary and Words checks look for it: whole words only, in any
 * capitals, its plural too ("bin" finds "Bins"; "pick list" finds "pick lists"; "category"
 * finds "categories"), with any spacing between the words of a phrase. Hyphens join words, so
 * "bin" is not found in "bin-01" or "re-bin".
 */
final class WordPattern
{
    private const BEFORE = '(?<![\p{L}\p{N}])(?<![\p{L}\p{N}]-)';

    private const AFTER = '(?![\p{L}\p{N}])(?!-[\p{L}\p{N}])';

    public static function of(string $words): string
    {
        return '/'.self::BEFORE.self::body($words).self::AFTER.'/iu';
    }

    private static function body(string $words): string
    {
        $words = trim((string) preg_replace('/\s+/u', ' ', $words));
        $quoted = str_replace(' ', '\s+', preg_quote($words, '/'));
        if (preg_match('/[^aeiou]y\z/iu', $words) === 1) {
            return mb_substr($quoted, 0, -1).'(?:y|ies)';
        }
        if (preg_match('/\p{L}\z/u', $words) === 1) {
            return $quoted.'(?:e?s)?';
        }

        return $quoted;
    }
}

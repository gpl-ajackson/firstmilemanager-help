<?php

namespace App\Records;

/**
 * Cleaning (spec #30, "Search Record"; user story 97): before a Search's words are saved,
 * anything that looks like an email address, phone number, UK postcode or long reference number
 * becomes a marker, so a reader's details never sit in a record. It errs towards replacing: a
 * marker in place of a harmless number costs the Help Gaps Report little, a kept detail costs
 * the reader their privacy.
 */
final class Cleaning
{
    /** Anything with an @ between non-space characters, trailing punctuation left outside. */
    private const EMAIL = '/[^\s@]+@[^\s@]*[^\s@.,;:!?)\]]/u';

    /**
     * A full UK postcode (outward and inward code), with or without the space, any capitals.
     * Launch Check 6 (personal details in the content) finds postcodes with it too.
     */
    public const POSTCODE = '/(?<![\p{L}\p{N}])(?:GIR\s?0AA|[A-PR-UWYZ][A-HK-Y]?\d[A-HJKPSTUW\d]?\s?\d[ABD-HJLNP-UW-Z]{2})(?![\p{L}\p{N}])/iu';

    /**
     * A phone number: + or a leading 0 (or "(0"), then digits in groups split by single spaces,
     * dots, hyphens or brackets ("+44 (0)115 496 0000", "07700 900123", "(0115) 4960000").
     * Launch Check 6 finds phone numbers in the content with it too, with its own digit counts.
     */
    public const PHONE = '/(?<![\p{L}\p{N}+])(?:\+\d{1,3}(?:[ .-]?\(0\))?|\(0\d+\)|0\d)(?:[ .-]?\d+)+/u';

    /** Digits in groups split by single spaces, dots, hyphens or slashes. */
    private const DIGITS = '/\d+(?:[ .\/-]\d+)*/u';

    /** A phone number has 10 to 13 digits (with its country code); fewer is not a phone. */
    private const PHONE_DIGITS = [10, 13];

    /** Six digits or more in one run is a long reference number (order, account or card). */
    private const LONG_NUMBER_DIGITS = 6;

    public static function words(string $words): string
    {
        $words = (string) preg_replace(self::EMAIL, '[email]', $words);
        $words = (string) preg_replace(self::POSTCODE, '[postcode]', $words);
        $words = (string) preg_replace_callback(self::PHONE, function (array $match): string {
            $digits = self::digitsIn($match[0]);

            return $digits >= self::PHONE_DIGITS[0] && $digits <= self::PHONE_DIGITS[1] ? '[phone]' : $match[0];
        }, $words);
        $words = (string) preg_replace_callback(
            self::DIGITS,
            fn (array $match): string => self::digitsIn($match[0]) >= self::LONG_NUMBER_DIGITS ? '[number]' : $match[0],
            $words,
        );

        return trim((string) preg_replace('/\s+/u', ' ', $words));
    }

    private static function digitsIn(string $text): int
    {
        return (int) preg_match_all('/\d/', $text);
    }
}

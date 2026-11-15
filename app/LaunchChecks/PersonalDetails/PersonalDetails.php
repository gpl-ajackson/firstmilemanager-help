<?php

namespace App\LaunchChecks\PersonalDetails;

use App\Records\Cleaning;

/**
 * Finds the email addresses, phone numbers and UK postcodes in a text, line by line. Postcodes
 * and phone numbers are found as Search Records' cleaning finds them (App\Records\Cleaning), so
 * the help centre has one idea of what they look like; email addresses need a whole address,
 * and a phone number in written help is a UK number of 10 or 11 digits (13 at most with a
 * country code), so barcodes and reference numbers aren't taken for one.
 */
final class PersonalDetails
{
    /** A whole email address: name, @, and a domain with at least one dot. */
    private const EMAIL = '/(?<![\p{L}\p{N}._%+-])[\p{L}\p{N}._%+-]+@[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)+/u';

    /** @return list<PersonalDetail> In the order they appear. */
    public static function in(string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $index => $line) {
            $found = [...$found, ...self::onLine($line, $index + 1)];
        }

        return $found;
    }

    /** @return list<PersonalDetail> */
    private static function onLine(string $line, int $number): array
    {
        $found = [];
        // Each detail is blanked out once found, so an email address's digits are never
        // taken for a phone number as well.
        foreach ([PersonalDetail::EMAIL => self::EMAIL, PersonalDetail::POSTCODE => Cleaning::POSTCODE, PersonalDetail::PHONE => Cleaning::PHONE] as $kind => $pattern) {
            preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$text, $offset]) {
                if ($kind === PersonalDetail::PHONE && ! self::isPhoneNumber($text)) {
                    continue;
                }
                $found[] = new PersonalDetail($kind, $text, $number, $offset);
                $line = substr_replace($line, str_repeat(' ', strlen($text)), $offset, strlen($text));
            }
        }
        usort($found, fn (PersonalDetail $a, PersonalDetail $b): int => $a->offset <=> $b->offset);

        return $found;
    }

    /** 10 or 11 digits from a leading 0 (a UK number), 10 to 13 with a country code. */
    private static function isPhoneNumber(string $text): bool
    {
        $digits = (int) preg_match_all('/\d/', str_replace('(0)', '', $text));

        return str_starts_with($text, '+') ? $digits >= 10 && $digits <= 13 : $digits >= 10 && $digits <= 11;
    }
}

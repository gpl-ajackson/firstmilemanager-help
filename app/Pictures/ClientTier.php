<?php

namespace App\Pictures;

/**
 * Client-tier Pictures (Picture Names starting `client-`) show a client's screens as any 3PL's
 * portal would (spec #30 "Pictures", rule 14, ADR-0005). The tool takes them with the team
 * renamed "Your 3PL" through Team Settings and Stockist's top bar cropped out, then reads the text
 * the Picture shows and fails the Recipe if Stockist's or the demo 3PL's name is in it.
 */
final class ClientTier
{
    /** What the team is called in a Client-tier Picture. */
    public const string TEAM_NAME = 'Your 3PL';

    /** Words a Client-tier Picture never shows, any capitals, inside other words too. */
    public const array NEVER_SHOWN = ['Stockist', 'Trentside'];

    /** How a broken Recipe names the rule, after "text check". */
    public const string RULE = 'a client- Picture never shows Stockist or Trentside';

    /**
     * Each word that must not show, with the first text the Picture shows it in.
     *
     * @param  list<string>  $shows  the texts the Picture shows
     * @return array<string, string>
     */
    public static function wordsShown(array $shows): array
    {
        $found = [];
        foreach (self::NEVER_SHOWN as $word) {
            foreach ($shows as $text) {
                if (mb_stripos($text, $word) !== false) {
                    $found[$word] = $text;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Why the Picture can't be used, or null when it shows none of the words.
     *
     * @param  list<string>  $shows
     */
    public static function problem(array $shows): ?string
    {
        $found = self::wordsShown($shows);
        if ($found === []) {
            return null;
        }

        return 'the Picture shows '.implode(', and ', array_map(
            fn (string $word, string $text): string => "\"{$word}\" in \"".self::excerpt($text, $word).'"',
            array_keys($found),
            $found,
        ));
    }

    /** The text, or the part of a long one around the word. */
    private static function excerpt(string $text, string $word): string
    {
        if (mb_strlen($text) <= 80) {
            return $text;
        }
        $at = (int) mb_stripos($text, $word);
        $start = max(0, $at - 30);

        return ($start > 0 ? '…' : '').trim(mb_substr($text, $start, 80)).($start + 80 < mb_strlen($text) ? '…' : '');
    }
}

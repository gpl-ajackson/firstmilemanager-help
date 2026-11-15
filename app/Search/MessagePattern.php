<?php

namespace App\Search;

/**
 * @internal A Troubleshooting message as a pattern (spec #30, "Search"): its words fixed and
 * each [Placeholder] open, so a message pasted with the reader's own codes in it still matches.
 *
 * Both sides are compared the same way: capitals, curly quotes, dashes and runs of spaces
 * don't matter, nor do the full stop or other marks at the end of the message.
 */
final class MessagePattern
{
    private const PLACEHOLDER = '/\[[^\[\]]+\]/u';

    /**
     * The pattern for a message as written in header data, or null for one with no words of
     * its own (all Placeholders), which would match anything.
     */
    public static function of(string $message): ?string
    {
        $parts = preg_split(self::PLACEHOLDER, self::normalise($message));
        if ($parts === false || preg_match('/\p{L}/u', implode('', $parts)) !== 1) {
            return null;
        }
        $last = count($parts) - 1;
        $parts[$last] = rtrim($parts[$last], '.!?…');

        $pattern = implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), $parts));

        // A fixed word at either end must be whole: "location" never matches inside "relocation".
        $start = preg_match('/^[\p{L}\p{N}]/u', $parts[0]) === 1 ? '(?<![\p{L}\p{N}])' : '';
        $end = preg_match('/[\p{L}\p{N}]$/u', $parts[$last]) === 1 ? '(?![\p{L}\p{N}])' : '';

        return '/'.$start.$pattern.$end.'/u';
    }

    /** Whether the pasted $words hold the message whose pattern is $pattern. */
    public static function matches(string $pattern, string $words): bool
    {
        return @preg_match($pattern, self::normalise($words)) === 1;
    }

    private static function normalise(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', "\u{00A0}" => ' ']);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

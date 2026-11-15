<?php

namespace App\LaunchChecks\Words;

use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Style Guide's countable word rules, from its header data (docs/writing/style-guide.md,
 * written by the foundation ticket, #70): the words it bans, each a word or phrase, or with
 * what to say instead.
 *
 *     ---
 *     banned_words:
 *       - please
 *       - e.g.
 *       - word: click
 *         say_instead: select
 *     ---
 *
 *     # Style Guide
 */
final readonly class StyleGuide
{
    /**
     * The banned words, or null when there is no Style Guide yet (the Words check then says it
     * didn't check them). A Style Guide it can't read fails as words.style-guide.
     *
     * @return list<WrongWord>|null
     */
    public static function bannedWords(?string $path, Findings $findings): ?array
    {
        if ($path === null || ! is_file($path)) {
            $findings->notRun("Words check, the Style Guide's banned words: there is no Style Guide at ".CheckContext::displayPath((string) $path));

            return null;
        }

        $file = CheckContext::displayPath($path);
        $fail = fn (string $message) => $findings->fail('words.style-guide', $file, 1, $message);
        $says = 'banned_words must be a list, each a word or phrase, or "word:" with "say_instead:"';

        if (preg_match('/\A---\R(.*?)\R---(?:\R|\z)/s', (string) file_get_contents($path), $parts) !== 1) {
            $fail('must start with header data between two "---" lines listing its banned_words');

            return [];
        }
        try {
            $header = Yaml::parse($parts[1]);
        } catch (ParseException $e) {
            $fail('header data is not valid YAML: '.$e->getMessage());

            return [];
        }
        $items = is_array($header) ? ($header['banned_words'] ?? null) : null;
        if (! is_array($items) || ! array_is_list($items)) {
            $fail($says);

            return [];
        }

        $words = [];
        foreach ($items as $item) {
            $word = is_array($item) ? ($item['word'] ?? null) : $item;
            $instead = is_array($item) ? ($item['say_instead'] ?? null) : null;
            if (! is_string($word) || trim($word) === '' || ($instead !== null && ! is_string($instead))
                || (is_array($item) && array_diff(array_keys($item), ['word', 'say_instead']) !== [])) {
                $fail($says);

                continue;
            }
            $words[] = new WrongWord(trim($word), WrongWord::BANNED, $instead === null ? [] : [trim($instead)]);
        }

        return $words;
    }
}

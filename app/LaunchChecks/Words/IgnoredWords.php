<?php

namespace App\LaunchChecks\Words;

use App\LaunchChecks\Findings;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Content Owner's Ignored Words, `ignored-words.yaml` in the content folder: GLOSSARY.md
 * avoid words the Words check skips because they are also everyday English in another sense.
 *
 *     - site
 *     - area
 */
final readonly class IgnoredWords
{
    public const FILE = 'ignored-words.yaml';

    /** @param  array<string, int>  $words  each word in lower case => its line */
    private function __construct(public array $words) {}

    public static function read(string $contentPath, Findings $findings): self
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            return new self([]);
        }

        $contents = (string) file_get_contents($path);
        try {
            $list = Yaml::parse($contents);
        } catch (ParseException $e) {
            $findings->fail('words.ignored-format', self::FILE, max(1, $e->getParsedLine()), 'is not valid YAML: '.$e->getMessage());

            return new self([]);
        }
        $list ??= [];
        if (! is_array($list) || ! array_is_list($list) || array_filter($list, fn (mixed $word): bool => ! is_string($word) || trim($word) === '') !== []) {
            $findings->fail('words.ignored-format', self::FILE, 1, 'must be a list of words, one "- " line each');

            return new self([]);
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $words = [];
        foreach ($list as $word) {
            $words[mb_strtolower(trim($word))] = self::lineOf($lines, trim($word));
        }

        return new self($words);
    }

    public function has(string $word): bool
    {
        return isset($this->words[mb_strtolower($word)]);
    }

    /** @param  list<string>  $lines */
    private static function lineOf(array $lines, string $word): int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*-\s*([\'"]?)'.preg_quote($word, '/').'\1\s*(#.*)?$/', $line) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }
}

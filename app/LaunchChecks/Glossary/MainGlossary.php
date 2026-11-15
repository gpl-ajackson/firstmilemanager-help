<?php

namespace App\LaunchChecks\Glossary;

/**
 * The main project's GLOSSARY.md, read as text: the domain language every Article and Glossary
 * Entry uses. Each term is a bold line ending in a colon, its definition under it, then an
 * "_Avoid_:" line of the words not to use for it:
 *
 *     **Location**:
 *     A specific, uniquely addressable physical space within a warehouse ...
 *     _Avoid_: Bin, slot, position, cell, storage unit
 *
 * A note in brackets after an avoid word ("Quarantine Location (that is for Quarantined
 * stock)") says why; it is not part of the word.
 */
final readonly class MainGlossary
{
    public const FILE = 'GLOSSARY.md';

    /** @param  array<string, GlossaryTerm>  $terms  term => the term, in GLOSSARY.md's order */
    private function __construct(public array $terms) {}

    public static function read(string $path): ?self
    {
        if (! is_file($path)) {
            return null;
        }

        return self::parse((string) file_get_contents($path));
    }

    public static function parse(string $markdown): self
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $terms = [];
        $count = count($lines);
        for ($index = 0; $index < $count; $index++) {
            if (preg_match('/^\*\*(.+?)\*\*:\s*$/', $lines[$index], $heading) !== 1) {
                continue;
            }
            $definition = [];
            $avoid = [];
            for ($next = $index + 1; $next < $count; $next++) {
                $line = trim($lines[$next]);
                if ($line === '' || str_starts_with($line, '#') || preg_match('/^\*\*(.+?)\*\*:\s*$/', $line) === 1) {
                    break;
                }
                if (preg_match('/^_Avoid_:\s*(.*)$/', $line, $avoidLine) === 1) {
                    $avoid = self::avoidWords($avoidLine[1]);

                    break;
                }
                $definition[] = $line;
            }
            $term = trim($heading[1]);
            $terms[$term] = new GlossaryTerm($term, implode(' ', $definition), $avoid, $index + 1);
        }

        return new self($terms);
    }

    public function has(string $term): bool
    {
        return isset($this->terms[$term]);
    }

    /** The term written with other capitals, when GLOSSARY.md has it that way. */
    public function spelledAs(string $term): ?string
    {
        foreach (array_keys($this->terms) as $name) {
            if (mb_strtolower($name) === mb_strtolower($term)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * An avoid line's words: split at commas outside brackets, each without its note.
     *
     * @return list<string>
     */
    private static function avoidWords(string $line): array
    {
        $words = [];
        $word = '';
        $depth = 0;
        foreach (mb_str_split($line) as $character) {
            if ($character === ',' && $depth === 0) {
                $words[] = $word;
                $word = '';

                continue;
            }
            $depth += match ($character) {
                '(' => 1,
                ')' => $depth > 0 ? -1 : 0,
                default => 0,
            };
            $word .= $character;
        }
        $words[] = $word;

        $clean = [];
        foreach ($words as $word) {
            $word = trim((string) preg_replace('/\([^)]*\)/', '', $word), " \t\"'`“”‘’.");
            $word = (string) preg_replace('/\s+/', ' ', $word);
            if ($word !== '') {
                $clean[] = $word;
            }
        }

        return $clean;
    }
}

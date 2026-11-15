<?php

namespace App\LaunchChecks\Words;

use App\LaunchChecks\WordPattern;

/**
 * A word help never uses: a GLOSSARY.md avoid word (with the terms to say instead) or a word
 * the Style Guide bans (with what to say instead, where it says).
 */
final readonly class WrongWord
{
    public const AVOID = 'avoid';

    public const BANNED = 'banned';

    public string $pattern;

    /** @param  list<string>  $sayInstead */
    public function __construct(
        public string $words,
        public string $kind,
        public array $sayInstead,
    ) {
        $this->pattern = WordPattern::of($words);
    }

    public function withSayInstead(string $term): self
    {
        return new self($this->words, $this->kind, array_values(array_unique([...$this->sayInstead, $term])));
    }

    /** What the check says about one use of it, written as $found. */
    public function message(string $found): string
    {
        $instead = implode(' or ', array_map(fn (string $term): string => "\"{$term}\"", $this->sayInstead));

        return $this->kind === self::AVOID
            ? "\"{$found}\" is a word GLOSSARY.md says to avoid: say {$instead} (to name it as the wrong word, put it in quotation marks)"
            : "\"{$found}\" is a word the Style Guide bans".($instead === '' ? '' : ": say {$instead}");
    }
}

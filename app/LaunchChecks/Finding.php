<?php

namespace App\LaunchChecks;

use Stringable;

/**
 * One thing a Launch Check found: a failure, which fails the pull request's build, or a report,
 * which is posted for the reviewer and never fails it. Each names the file, the line where
 * there is one, and the rule (check.rule), so a drafting agent can fix it without guessing.
 */
final readonly class Finding implements Stringable
{
    private function __construct(
        public bool $fails,
        public string $rule,
        public string $file,
        public ?int $line,
        public string $message,
    ) {}

    public static function failure(string $rule, string $file, ?int $line, string $message): self
    {
        return new self(true, $rule, $file, $line, $message);
    }

    public static function report(string $rule, string $file, ?int $line, string $message): self
    {
        return new self(false, $rule, $file, $line, $message);
    }

    /** "articles/x.md:7", or the file alone when the finding has no line. */
    public function where(): string
    {
        return $this->line === null ? $this->file : "{$this->file}:{$this->line}";
    }

    public function __toString(): string
    {
        return "{$this->where()} [{$this->rule}] {$this->message}";
    }
}

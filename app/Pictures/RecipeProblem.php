<?php

namespace App\Pictures;

use Stringable;

/** One thing wrong with a Picture Recipe, naming its file and field. */
final readonly class RecipeProblem implements Stringable
{
    public function __construct(
        public string $file,
        public string $field,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return $this->field === '' ? "{$this->file}: {$this->message}" : "{$this->file}: {$this->field}: {$this->message}";
    }
}

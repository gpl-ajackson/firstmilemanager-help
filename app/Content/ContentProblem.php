<?php

namespace App\Content;

use Stringable;

/** One thing wrong in the content folder, naming the file and the field. */
final readonly class ContentProblem implements Stringable
{
    public function __construct(
        public string $file,
        public string $field,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return "{$this->file}: {$this->field}: {$this->message}";
    }
}

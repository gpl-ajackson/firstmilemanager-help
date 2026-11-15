<?php

namespace App\LaunchChecks\ArticleTemplate;

/**
 * One part of an Article Template: a "## <name>" heading in the Article's body, or, when the
 * page builds the part from header data (related articles, a Troubleshooting Article's
 * messages), that header field.
 */
final readonly class TemplatePart
{
    public function __construct(
        public string $name,
        public bool $required,
        public ?string $field,
    ) {}

    public function describe(): string
    {
        return $this->field === null ? "\"## {$this->name}\"" : "\"{$this->name}\" (header data field {$this->field})";
    }
}

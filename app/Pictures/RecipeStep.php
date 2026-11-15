<?php

namespace App\Pictures;

/**
 * One step of a Picture Recipe, in the screen's words: go to a menu route, select what shows
 * some words (or a choice in a labelled field), enter text in a labelled field, scan a code
 * into the Rapid Scanner, or wait for words to show. `in` narrows it to the part of the screen
 * headed by those words.
 */
final readonly class RecipeStep
{
    public const array KINDS = ['go_to', 'select', 'enter', 'scan', 'wait_for'];

    /**
     * @param  'go_to'|'select'|'enter'|'scan'|'wait_for'  $kind
     * @param  string  $said  the step as the Recipe writes it, for reports
     */
    public function __construct(
        public string $kind,
        public string $words,
        public ?string $field,
        public ?string $in,
        public string $said,
    ) {}

    /**
     * What the camera needs (app/Pictures/node/take-picture.cjs).
     *
     * @return array<string, mixed>
     */
    public function forCamera(): array
    {
        return [
            'kind' => $this->kind,
            'words' => $this->words,
            'field' => $this->field,
            'in' => $this->in,
            'route' => $this->kind === 'go_to' ? Recipe::route($this->words) : null,
            'said' => $this->said,
        ];
    }
}

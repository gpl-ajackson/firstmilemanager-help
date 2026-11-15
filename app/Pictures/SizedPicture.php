<?php

namespace App\Pictures;

/**
 * A Picture or diagram as a page shows it: its alt text, its size on the page in CSS pixels,
 * and the sized files made from its double-density file, so the reader's browser fetches only
 * the size it needs.
 */
final readonly class SizedPicture
{
    /**
     * @param  'desk'|'handheld'|'diagram'  $kind
     * @param  non-empty-array<int, string>  $sources  Each sized file's address, by its width in pixels, narrowest first.
     */
    public function __construct(
        public string $kind,
        public string $alt,
        public int $width,
        public int $height,
        public array $sources,
    ) {}

    /** The file a browser that reads no srcset shows: the one at the page's own size. */
    public function src(): string
    {
        return $this->sources[$this->width] ?? $this->sources[array_key_last($this->sources)];
    }

    public function srcset(): string
    {
        return implode(', ', array_map(fn (int $width, string $address): string => "{$address} {$width}w", array_keys($this->sources), $this->sources));
    }

    /**
     * How wide the picture is drawn, so the browser picks a file before it has the styles. The
     * article's text column (public/css/site.css, .prose) is at most 68ch, about 690 pixels,
     * less the page's gutters on a phone; a handheld Picture and a diagram are never drawn
     * wider than their own size.
     */
    public function sizes(): string
    {
        return $this->kind === 'desk'
            ? '(min-width: 760px) 690px, calc(100vw - 32px)'
            : '(min-width: '.($this->width + 32)."px) {$this->width}px, calc(100vw - 32px)";
    }
}

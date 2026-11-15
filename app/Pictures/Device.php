<?php

namespace App\Pictures;

/**
 * What a Picture is taken on: a desk screen, or a handheld (the Rapid Scanner and the Dock
 * Receiving Terminal). Pictures are taken at double density, so a desk Picture is 2560 pixels
 * wide and a handheld one 720; only their height follows what the Recipe keeps.
 */
enum Device: string
{
    case Desk = 'desk';
    case Handheld = 'handheld';

    /** The screen's width in CSS pixels, as the page lays it out. */
    public function width(): int
    {
        return match ($this) {
            self::Desk => 1280,
            self::Handheld => 360,
        };
    }

    /** The screen's height in CSS pixels: what "keep: screen" keeps. */
    public function height(): int
    {
        return match ($this) {
            self::Desk => 800,
            self::Handheld => 640,
        };
    }

    /** Pictures are taken at double density. */
    public const int DENSITY = 2;
}

<?php

namespace App\Pictures;

use GdImage;
use RuntimeException;

/**
 * What Comparison found between a Picture and a new take of it: their sizes and the pixels a
 * reader could see changed. draw() makes the difference image the Picture Check leaves as a
 * build artefact: the new take faded to grey, every changed pixel marked in magenta, and a box
 * around them all, so the eye finds a change of a few pixels on a 2560-pixel screen.
 */
final readonly class Difference
{
    /**
     * @param  array{int<1, max>, int<1, max>}  $before  width and height
     * @param  array{int<1, max>, int<1, max>}  $after  width and height
     * @param  list<array{int, int}>  $marks  each changed pixel, x and y
     */
    public function __construct(
        public array $before,
        public array $after,
        public array $marks,
        private string $afterPath,
    ) {}

    public function changed(): bool
    {
        return $this->before !== $this->after || count($this->marks) >= Comparison::VISIBLE;
    }

    /**
     * What changed, in words: "1,234 pixels differ, from (412, 30) to (440, 58)", or the size it
     * was and is. The corners, in the Picture's own pixels, let a reader who can't open the
     * images find the change.
     */
    public function why(): string
    {
        $pixels = number_format(count($this->marks)).' '.str('pixel')->plural(count($this->marks)).' differ';
        if ($this->marks !== []) {
            $xs = array_column($this->marks, 0);
            $ys = array_column($this->marks, 1);
            $pixels .= ', from ('.min($xs).', '.min($ys).') to ('.max($xs).', '.max($ys).')';
        }
        if ($this->before === $this->after) {
            return $pixels;
        }

        return "it is {$this->after[0]}×{$this->after[1]} now, was {$this->before[0]}×{$this->before[1]}"
            .($this->marks === [] ? '' : ", and {$pixels} where they overlap");
    }

    /** Writes the difference image to $file, at the size of the larger of the two. */
    public function draw(string $file): void
    {
        $after = @imagecreatefrompng($this->afterPath);
        if ($after === false) {
            throw new RuntimeException("{$this->afterPath} isn't a PNG image.");
        }
        $width = max($this->before[0], $this->after[0]);
        $height = max($this->before[1], $this->after[1]);

        $image = imagecreatetruecolor($width, $height);
        $magenta = self::colour($image, 230, 0, 126);
        $outside = self::colour($image, 250, 205, 228);
        imagefill($image, 0, 0, $outside);
        imagecopy($image, $after, 0, 0, 0, 0, $this->after[0], $this->after[1]);
        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_COLORIZE, 100, 100, 100);

        // Whatever only one of the two covers is changed too.
        $overlapWidth = min($this->before[0], $this->after[0]);
        $overlapHeight = min($this->before[1], $this->after[1]);
        if ($overlapWidth < $width) {
            imagefilledrectangle($image, $overlapWidth, 0, $width - 1, $height - 1, $outside);
        }
        if ($overlapHeight < $height) {
            imagefilledrectangle($image, 0, $overlapHeight, $width - 1, $height - 1, $outside);
        }

        if ($this->marks !== []) {
            $left = $width;
            $top = $height;
            $right = 0;
            $bottom = 0;
            foreach ($this->marks as [$x, $y]) {
                imagefilledrectangle($image, $x - 1, $y - 1, $x + 1, $y + 1, $magenta);
                $left = min($left, $x);
                $top = min($top, $y);
                $right = max($right, $x);
                $bottom = max($bottom, $y);
            }
            imagesetthickness($image, 4);
            imagerectangle($image, max(0, $left - 12), max(0, $top - 12), min($width - 1, $right + 12), min($height - 1, $bottom + 12), $magenta);
        }

        imagepng($image, $file);
    }

    /**
     * @param  int<0, 255>  $red
     * @param  int<0, 255>  $green
     * @param  int<0, 255>  $blue
     */
    private static function colour(GdImage $image, int $red, int $green, int $blue): int
    {
        $colour = imagecolorallocate($image, $red, $green, $blue);
        if ($colour === false) {
            throw new RuntimeException("Couldn't make the colour {$red}, {$green}, {$blue}.");
        }

        return $colour;
    }
}

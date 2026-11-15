<?php

namespace App\Pictures;

use GdImage;
use RuntimeException;

/**
 * Whether a reader could see the difference between two takes of a Picture (spec #30
 * "Pictures": font smoothing ignored by a threshold). The same Stockist, seed and moment give
 * the same image byte for byte on one machine; on another, the words can be smoothed a little
 * differently, or drawn a fraction of a pixel along. So a pixel counts as changed only when
 * nothing explains it:
 *
 * - the other take has a pixel of about the same colour (TOLERANCE) within SPREAD pixels, so
 *   an edge that moved by a pixel isn't a change; or
 * - it is a smoothed edge (its colour lies between its own neighbours') and the other take has
 *   both darker and lighter pixels around it, as smoothing on, off or shaded differently gives.
 *
 * A Picture changed when its size changed or at least VISIBLE pixels did: fewer is a speck no
 * reader would see at double density.
 */
final class Comparison
{
    /** How far a channel (0 to 255) may differ and still be the same colour. */
    public const int TOLERANCE = 32;

    /** How many pixels a smoothed edge may move. */
    public const int SPREAD = 1;

    /** The fewest changed pixels a reader could see: about 2×2 CSS pixels at double density. */
    public const int VISIBLE = 10;

    public static function of(string $before, string $after): Difference
    {
        $old = self::load($before);
        $new = self::load($after);
        $width = min(imagesx($old), imagesx($new));
        $height = min(imagesy($old), imagesy($new));

        $marks = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $was = self::at($old, $x, $y);
                $is = self::at($new, $x, $y);
                if ($was === $is) {
                    continue;
                }
                if (! self::explained($was, $x, $y, $old, $new, $width, $height)
                    || ! self::explained($is, $x, $y, $new, $old, $width, $height)) {
                    $marks[] = [$x, $y];
                }
            }
        }

        return new Difference(
            before: [imagesx($old), imagesy($old)],
            after: [imagesx($new), imagesy($new)],
            marks: $marks,
            afterPath: $after,
        );
    }

    /**
     * Whether the colour at (x, y) in $own is explained by what $other shows around (x, y).
     */
    private static function explained(int $colour, int $x, int $y, GdImage $own, GdImage $other, int $width, int $height): bool
    {
        [$red, $green, $blue] = self::channels($colour);
        $low = [255, 255, 255];
        $high = [0, 0, 0];
        $ownDarkest = 255.0;
        $ownLightest = 0.0;

        for ($dy = -self::SPREAD; $dy <= self::SPREAD; $dy++) {
            for ($dx = -self::SPREAD; $dx <= self::SPREAD; $dx++) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                    continue;
                }
                $near = self::channels(self::at($other, $nx, $ny));
                if (abs($near[0] - $red) <= self::TOLERANCE && abs($near[1] - $green) <= self::TOLERANCE && abs($near[2] - $blue) <= self::TOLERANCE) {
                    return true;
                }
                for ($channel = 0; $channel < 3; $channel++) {
                    $low[$channel] = min($low[$channel], $near[$channel]);
                    $high[$channel] = max($high[$channel], $near[$channel]);
                }
                if ($dx !== 0 || $dy !== 0) {
                    $lightness = self::lightness(self::channels(self::at($own, $nx, $ny)));
                    $ownDarkest = min($ownDarkest, $lightness);
                    $ownLightest = max($ownLightest, $lightness);
                }
            }
        }

        $lightness = self::lightness([$red, $green, $blue]);
        $margin = self::TOLERANCE / 2;
        $smoothedEdge = $lightness > $ownDarkest + $margin && $lightness < $ownLightest - $margin;
        if (! $smoothedEdge) {
            return false;
        }
        foreach ([$red, $green, $blue] as $channel => $value) {
            if ($value < $low[$channel] - self::TOLERANCE || $value > $high[$channel] + self::TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /** The colour at a point inside the image, as 0xRRGGBB. */
    private static function at(GdImage $image, int $x, int $y): int
    {
        $colour = imagecolorat($image, $x, $y);
        if ($colour === false) {
            throw new RuntimeException("({$x}, {$y}) is outside the image.");
        }

        return $colour;
    }

    /** @return array{int, int, int} */
    private static function channels(int $colour): array
    {
        return [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF];
    }

    /** @param  array{int, int, int}  $channels */
    private static function lightness(array $channels): float
    {
        return 0.299 * $channels[0] + 0.587 * $channels[1] + 0.114 * $channels[2];
    }

    private static function load(string $path): GdImage
    {
        $image = @imagecreatefrompng($path);
        if ($image === false) {
            throw new RuntimeException("{$path} isn't a PNG image.");
        }
        imagepalettetotruecolor($image);

        return $image;
    }
}

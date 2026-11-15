<?php

namespace App\Pictures;

use GdImage;
use RuntimeException;

/**
 * The Pictures and diagrams a page shows (spec #30, Renderer; ADR-0005). It reads the pictures
 * folder, where the picture tool leaves each Picture beside its Picture Recipe:
 *
 *     pictures/<Picture Name>.png    the Picture, double density: 2560 wide (desk) or 720 (handheld)
 *     pictures/<Picture Name>.yaml   its Recipe (#62); a page needs its alt and device (desk or handheld)
 *
 * During the content rebuild it makes each Picture's sized files (half and full size, and a
 * quarter for desk Pictures, as lossless WebP) and draws each Mermaid diagram as an image, into
 * the served pictures folder. Every file there sits under a fingerprint of what it was made
 * from, so its address changes whenever it does and it can be cached for a year. Files already
 * made are kept, so a rebuild only makes what changed, and a page from the previous rebuild
 * still finds its files while a deploy switches over.
 */
final class PictureLibrary
{
    /** A Picture Name: lower-case letters and numbers joined by single hyphens, `client-` first for client tier. */
    public const NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** How wide the picture tool takes each kind of Picture: double density, 1280 and 360 CSS pixels. */
    public const TOOL_WIDTHS = ['desk' => 2560, 'handheld' => 720];

    /** The sized files made from each kind of Picture, in pixels. */
    private const SIZES = ['desk' => [640, 1280, 2560], 'handheld' => [360, 720]];

    /** Changing how sized files are made changes this, so every one is made again. */
    private const SIZING = 'webp-lossless-1';

    private const FINGERPRINT = '/\A[0-9a-f]{16}\z/';

    private const SIZED_FILE = '/\A[a-z0-9]+(?:-[a-z0-9]+)*-[1-9][0-9]*\.webp\z/';

    public function __construct(
        private readonly string $picturesPath,
        private readonly string $servedPath,
        private readonly DiagramDrawer $drawer,
    ) {}

    /** The same library reading another pictures folder (help:rebuild --pictures). */
    public function from(string $picturesPath): self
    {
        return new self(rtrim($picturesPath, '/'), $this->servedPath, $this->drawer);
    }

    /**
     * A Picture by its Picture Name, sized for the page, or what is wrong with it in words.
     */
    public function picture(string $name): SizedPicture|string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return "\"{$name}\" isn't a Picture Name: lower-case letters and numbers joined by single hyphens";
        }

        $file = "{$this->picturesPath}/{$name}.png";
        if (! is_file($file)) {
            return "there is no Picture named \"{$name}\" ({$name}.png in the pictures folder)";
        }
        $recipe = $this->recipe($name);
        if (is_string($recipe)) {
            return $recipe;
        }
        $alt = $recipe->alt;
        $device = $recipe->device->value;

        $size = @getimagesize($file);
        if ($size === false || $size[2] !== IMAGETYPE_PNG) {
            return "{$name}.png isn't a PNG image";
        }
        [$width, $height] = $size;
        if ($width !== self::TOOL_WIDTHS[$device]) {
            return "{$name}.png is {$width} pixels wide, but the picture tool takes a {$device} Picture ".self::TOOL_WIDTHS[$device].' wide';
        }

        $fingerprint = self::fingerprint(self::SIZING.(string) file_get_contents($file));
        $sources = $this->size(fn (): GdImage => self::load($file), $fingerprint, $name, self::SIZES[$device]);

        return new SizedPicture($device, $alt, intdiv($width, 2), intdiv($height, 2), $sources);
    }

    /**
     * A Picture's alt text, from its Recipe, without sizing anything (the Launch Checks read it);
     * null when its Recipe is missing or can't give one (the rebuild says why).
     */
    public function alt(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $recipe = $this->recipe($name);

        return $recipe instanceof Recipe ? $recipe->alt : null;
    }

    /**
     * Each Mermaid diagram drawn as an image and sized for the page, or what is wrong with it in
     * words. Diagrams drawn before are taken as they are; the rest are drawn together.
     *
     * @param  list<string>  $sources  Each diagram's Mermaid text.
     * @return list<SizedPicture|string> In the same order.
     */
    public function diagrams(array $sources): array
    {
        $results = [];
        $toDraw = [];
        foreach ($sources as $index => $source) {
            $alt = self::diagramAlt($source);
            if ($alt === null) {
                $results[$index] = 'write its alt text in words as "accDescr: <what it shows>" on the line after "'.self::diagramType($source).'"';

                continue;
            }

            $fingerprint = self::fingerprint(self::SIZING.$this->drawer->version().$source);
            $results[$index] = $this->drawnDiagram($fingerprint, $alt);
            if ($results[$index] === null) {
                $toDraw[$index] = ['source' => $source, 'file' => "{$this->servedPath}/{$fingerprint}/diagram.png", 'fingerprint' => $fingerprint, 'alt' => $alt];
            }
        }

        if ($toDraw !== []) {
            try {
                $drawn = $this->drawer->draw(array_values(array_map(fn (array $diagram): array => ['source' => $diagram['source'], 'file' => $diagram['file']], $toDraw)));
            } catch (RuntimeException $failure) {
                $drawn = [];
                foreach (array_keys($toDraw) as $index) {
                    $results[$index] = "can't be drawn on this machine: {$failure->getMessage()}";
                }
                $toDraw = [];
            }

            foreach (array_keys($toDraw) as $position => $index) {
                $diagram = $toDraw[$index];
                $result = $drawn[$position];
                if (isset($result['error'])) {
                    // Mermaid counts lines its own way, which wouldn't match the Article's.
                    $results[$index] = "Mermaid can't read it: ".preg_replace('/^Parse error on line \d+:/', 'Parse error:', $result['error']);

                    continue;
                }
                $this->size(fn (): GdImage => self::load($diagram['file']), $diagram['fingerprint'], 'diagram', [$result['width'], 2 * $result['width']]);
                @unlink($diagram['file']);
                $results[$index] = $this->drawnDiagram($diagram['fingerprint'], $diagram['alt'])
                    ?? 'was drawn, but its image files are missing';
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * The file at a served picture's address, or null for an address it never served.
     */
    public function servedFile(string $fingerprint, string $file): ?string
    {
        if (preg_match(self::FINGERPRINT, $fingerprint) !== 1 || preg_match(self::SIZED_FILE, $file) !== 1) {
            return null;
        }
        $path = "{$this->servedPath}/{$fingerprint}/{$file}";

        return is_file($path) ? $path : null;
    }

    /**
     * The Picture's Recipe, read and checked as the picture tool reads it (App\Pictures\Recipe),
     * or what is wrong with it in words.
     */
    private function recipe(string $name): Recipe|string
    {
        $file = "{$this->picturesPath}/{$name}.yaml";
        if (! is_file($file)) {
            return "\"{$name}\" has no Recipe ({$name}.yaml) to give its alt text";
        }

        try {
            return Recipe::read($file);
        } catch (InvalidRecipe $invalid) {
            return "the Recipe {$name}.yaml can't be used: ".implode('; ', array_map(
                fn (RecipeProblem $problem): string => $problem->field === '' ? $problem->message : "{$problem->field}: {$problem->message}",
                $invalid->problems,
            ));
        }
    }

    /** A diagram drawn by an earlier rebuild, or null if it hasn't been. */
    private function drawnDiagram(string $fingerprint, string $alt): ?SizedPicture
    {
        $sources = [];
        foreach (glob("{$this->servedPath}/{$fingerprint}/diagram-*.webp") ?: [] as $file) {
            $sources[(int) substr(basename($file, '.webp'), strlen('diagram-'))] = "/pictures/{$fingerprint}/".basename($file);
        }
        ksort($sources);
        if (count($sources) !== 2) {
            return null;
        }
        $width = (int) array_key_first($sources);
        $size = @getimagesize("{$this->servedPath}/{$fingerprint}/diagram-{$width}.webp");

        return $size === false ? null : new SizedPicture('diagram', $alt, $width, $size[1], $sources);
    }

    /**
     * Makes each sized file not already made, as lossless WebP (sharp words, small files for
     * screens), and gives every sized file's address by its width.
     *
     * @param  callable(): GdImage  $load
     * @param  non-empty-list<int>  $widths
     * @return non-empty-array<int, string>
     */
    private function size(callable $load, string $fingerprint, string $base, array $widths): array
    {
        $folder = "{$this->servedPath}/{$fingerprint}";
        $image = null;
        $sources = [];
        foreach ($widths as $width) {
            $file = "{$base}-{$width}.webp";
            if (! is_file("{$folder}/{$file}")) {
                $image ??= $load();
                $sized = $width === imagesx($image) ? $image : imagescale($image, $width, -1, IMG_BICUBIC);
                if ($sized === false) {
                    throw new RuntimeException("Couldn't size {$base} to {$width} pixels");
                }
                imagealphablending($sized, false);
                imagesavealpha($sized, true);
                self::write($folder, $file, $sized);
            }
            $sources[$width] = "/pictures/{$fingerprint}/{$file}";
        }

        return $sources;
    }

    /** Writes beside the file, then moves it into place, so no reader ever gets half a file. */
    private static function write(string $folder, string $file, GdImage $image): void
    {
        if (! is_dir($folder) && ! @mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new RuntimeException("Couldn't make the folder {$folder}");
        }
        $partial = "{$folder}/.{$file}.".bin2hex(random_bytes(4));
        if (! imagewebp($image, $partial, IMG_WEBP_LOSSLESS) || ! rename($partial, "{$folder}/{$file}")) {
            @unlink($partial);
            throw new RuntimeException("Couldn't write {$folder}/{$file}");
        }
    }

    private static function load(string $file): GdImage
    {
        $image = @imagecreatefrompng($file);
        if ($image === false) {
            throw new RuntimeException("Couldn't read {$file} as a PNG image");
        }

        return $image;
    }

    private static function fingerprint(string $contents): string
    {
        return substr(hash('xxh128', $contents), 0, 16);
    }

    /**
     * A diagram's alt text: Mermaid's own accessible description, written `accDescr: <words>`
     * on one line or `accDescr { <words> }` over several. Null when it has none.
     */
    private static function diagramAlt(string $source): ?string
    {
        if (preg_match('/^\s*accDescr\s*:\s*(.+?)\s*$/m', $source, $line) === 1
            || preg_match('/^\s*accDescr\s*\{(.*?)\}/ms', $source, $line) === 1) {
            $alt = trim((string) preg_replace('/\s+/', ' ', $line[1]));

            return $alt === '' ? null : $alt;
        }

        return null;
    }

    /** The diagram's first line, naming its type ("flowchart LR"), for messages to the writer. */
    private static function diagramType(string $source): string
    {
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! str_starts_with($line, '%%') && $line !== '---') {
                return $line;
            }
        }

        return 'the diagram type';
    }
}

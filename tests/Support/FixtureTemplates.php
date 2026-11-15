<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * A small docs/writing/templates folder for one test: Article Templates as the foundation
 * ticket (#70) writes them, whose header data lists each Article Type's parts in page order.
 *
 *     templates()->template('How-to', ['In short', 'Steps', ['part' => 'Decide first', 'when_needed' => true]]);
 *
 * A part given as a string is a required "## <part>" heading in the Article's body.
 */
final class FixtureTemplates
{
    /** @var list<string> */
    private static array $made = [];

    private function __construct(private readonly string $path) {}

    public static function make(): self
    {
        $path = sys_get_temp_dir().'/help-templates-'.getmypid().'-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($path);
        self::$made[] = $path;

        return new self($path);
    }

    public static function removeAll(): void
    {
        foreach (self::$made as $path) {
            File::deleteDirectory($path);
        }
        self::$made = [];
    }

    /**
     * @param  list<string|array<string, mixed>>  $parts
     */
    public function template(string $type, array $parts, ?int $guideWords = null): self
    {
        $header = array_filter([
            'template' => $type,
            'guide_words' => $guideWords,
            'parts' => array_map(fn (string|array $part): array => is_string($part) ? ['part' => $part] : $part, $parts),
        ], fn (mixed $value): bool => $value !== null);

        return $this->file(Str::slug($type).'.md', "---\n".Yaml::dump($header, 4, 2)."---\n\nHow to write a {$type} Article.\n");
    }

    /** Writes any file into the folder, exactly as given. */
    public function file(string $relativePath, string $contents): self
    {
        File::ensureDirectoryExists(dirname($this->path.'/'.$relativePath));
        File::put($this->path.'/'.$relativePath, $contents);

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }
}

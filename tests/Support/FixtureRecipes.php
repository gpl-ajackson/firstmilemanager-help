<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * A small pictures folder for one test, laid out as the real one (pictures/): Picture Recipes
 * as <Picture Name>.yaml, taken through the same command a writer runs.
 *
 *     recipes()
 *         ->recipe('warehouses-locations-list', ['outline' => 'New Location'])
 *         ->take(['--into' => $folder]);
 *
 * Every Recipe is valid by default: Sarah Whitfield on Warehouses › Locations at the desk.
 * Pass only the fields a test is about; a field set to null is left out of the file.
 */
final class FixtureRecipes
{
    /** The Recipe every fixture starts from: a desk Picture of the Locations list. */
    public const DEFAULT_RECIPE = [
        'signs_in_as' => 'Sarah Whitfield',
        'starts_on' => 'Warehouses › Locations',
        'device' => 'desk',
        'steps' => [],
        'keep' => 'Locations',
        'outline' => 'New Location',
        'alt' => 'The Locations list, with New Location at the top right.',
        'articles' => ['add-a-location'],
    ];

    /** @var list<string> */
    private static array $made = [];

    private function __construct(private readonly string $path) {}

    public static function make(): self
    {
        $path = self::folder('help-pictures');
        self::$made[] = $path;

        return new self($path);
    }

    /** A new empty folder, removed after the test, for the Pictures a run takes. */
    public static function folder(string $prefix = 'help-taken'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.getmypid().'-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($path);
        self::$made[] = $path;

        return $path;
    }

    /** Deletes every folder made in this test (TestCase::tearDown calls it). */
    public static function removeAll(): void
    {
        foreach (self::$made as $path) {
            File::deleteDirectory($path);
        }
        self::$made = [];
    }

    /**
     * Writes <name>.yaml: the default Recipe overridden by $fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function recipe(string $name, array $fields = []): self
    {
        $recipe = array_filter(array_merge(self::DEFAULT_RECIPE, $fields), fn (mixed $value): bool => $value !== null);

        return $this->file($name.'.yaml', Yaml::dump($recipe, 4, 2));
    }

    /** Writes any file into the folder as given. */
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

    /**
     * Runs php artisan pictures:take over this folder.
     *
     * @param  array<string, mixed>  $options
     * @return array{exitCode: int, output: string}
     */
    public function take(array $options = []): array
    {
        $exitCode = Artisan::call('pictures:take', ['--pictures' => $this->path, ...$options]);

        return ['exitCode' => $exitCode, 'output' => Artisan::output()];
    }
}

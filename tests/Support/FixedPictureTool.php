<?php

namespace Tests\Support;

use App\Pictures\PictureRun;
use App\Pictures\PictureTool;
use App\Pictures\TakenPicture;
use Closure;
use Illuminate\Support\Facades\File;

/**
 * The picture tool with Stockist left out, for fast tests of what is done with its Pictures
 * (the Release Check): each Recipe "takes" the image given for it, or its committed Picture
 * unchanged, and it remembers where it was asked to take them from. The real Picture Check
 * compares what it takes. Bind it with app()->instance(PictureTool::class, $tool).
 *
 *     $tool = FixedPictureTool::taking($content->picturesPath(), ['warehouses-locations-list' => $png]);
 */
final class FixedPictureTool extends PictureTool
{
    /** @var list<array{repository: ?string, ref: ?string, recipes: list<string>}> each take() asked of it */
    public array $asked = [];

    /**
     * @param  list<string>  $pictures
     * @param  array<string, string>  $takes  Picture Name => the PNG its Recipe takes now
     */
    private function __construct(private readonly array $pictures, private readonly array $takes) {}

    /**
     * @param  string|list<string>  $pictures  the pictures folders (this repository's, First Mile Manager's), whose Pictures the other Recipes take unchanged
     * @param  array<string, string>  $takes  Picture Name => the PNG its Recipe takes now
     */
    public static function taking(string|array $pictures, array $takes): self
    {
        $tool = new self(is_array($pictures) ? $pictures : [$pictures], $takes);
        app()->instance(PictureTool::class, $tool);

        return $tool;
    }

    public function take(array $recipes, string $into, ?string $repository = null, ?string $ref = null, ?Closure $say = null): PictureRun
    {
        $this->asked[] = ['repository' => $repository, 'ref' => $ref, 'recipes' => array_map(fn ($recipe): string => $recipe->name, $recipes)];
        File::ensureDirectoryExists($into);

        $taken = [];
        foreach ($recipes as $recipe) {
            $path = "{$into}/{$recipe->name}.png";
            if (isset($this->takes[$recipe->name])) {
                File::put($path, $this->takes[$recipe->name]);
            } else {
                $committed = array_values(array_filter($this->pictures, fn (string $folder): bool => is_file("{$folder}/{$recipe->name}.png")));
                File::copy("{$committed[0]}/{$recipe->name}.png", $path);
            }
            [$width, $height] = getimagesize($path) ?: [0, 0];
            $taken[] = new TakenPicture($recipe, $path, $width, $height);
        }

        return new PictureRun(str_repeat('5', 40), $taken, []);
    }
}

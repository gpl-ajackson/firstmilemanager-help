<?php

namespace App\Pictures;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * The Picture Check (spec #30 "Pictures", Launch Check 12, ADR-0005): retakes Pictures from
 * their Recipes with the picture tool and lists each one a reader would see differ from the
 * Picture in the pictures folder, and each Recipe that no longer works. For every changed
 * Picture it leaves three images in the artefacts folder for the reviewer:
 *
 *     <Picture Name>.before.png       the Picture as it is in the pictures folder
 *     <Picture Name>.after.png        what its Recipe takes now
 *     <Picture Name>.difference.png   the new take faded, what changed marked in magenta
 *     <Picture Name>.broken.png       for a broken Recipe, the screen where it broke
 *
 * Launch Check 12 runs it on a support-site pull request's added or changed Pictures (against
 * main or the paired change), and on every Picture for the Release Check and the Help Drift
 * Report (help:launch-checks --take-pictures). A main-project pull request runs it on every
 * Picture through php artisan pictures:check (#65).
 */
final readonly class PictureCheck
{
    public function __construct(private PictureTool $tool) {}

    /** Where the images are left when no folder is given: storage/pictures/check. */
    public static function defaultArtefacts(): string
    {
        return rtrim(Config::string('help.pictures.work_path'), '/').'/check';
    }

    /**
     * Retakes $recipes from Stockist at $ref and compares each with its Picture in $picturesPath.
     *
     * @param  list<Recipe>  $recipes
     * @param  Closure(string): void|null  $say  progress, one line at a time
     *
     * @throws StockistUnavailable
     */
    public function run(array $recipes, string $picturesPath, string $artefacts, ?string $repository = null, ?string $ref = null, ?Closure $say = null): CheckedPictures
    {
        $retaken = rtrim(Config::string('help.pictures.work_path'), '/').'/retaken-'.Str::lower(Str::random(8));
        try {
            $run = $this->tool->take($recipes, into: $retaken, repository: $repository, ref: $ref, say: $say);

            return $this->compare($run, $picturesPath, $artefacts);
        } finally {
            File::deleteDirectory($retaken);
        }
    }

    /**
     * Compares each Picture a run of the picture tool took with the Picture of the same name in
     * $picturesPath, leaving the images for each one that changed (and each broken Recipe's
     * screen) in $artefacts, which is emptied first.
     */
    public function compare(PictureRun $run, string $picturesPath, string $artefacts): CheckedPictures
    {
        $artefacts = rtrim($artefacts, '/');
        File::deleteDirectory($artefacts);
        File::ensureDirectoryExists($artefacts);

        $changed = [];
        $same = [];
        foreach ($run->taken as $taken) {
            $name = $taken->recipe->name;
            $committed = rtrim($picturesPath, '/')."/{$name}.png";
            $after = "{$artefacts}/{$name}.after.png";

            if (! is_file($committed)) {
                File::copy($taken->path, $after);
                $changed[] = new ChangedPicture($taken->recipe, null, $after, null, "it has no Picture yet; take it with php artisan pictures:take {$name}");

                continue;
            }

            $difference = md5_file($committed) === md5_file($taken->path) ? null : Comparison::of($committed, $taken->path);
            if ($difference === null || ! $difference->changed()) {
                $same[] = $name;

                continue;
            }

            File::copy($committed, "{$artefacts}/{$name}.before.png");
            File::copy($taken->path, $after);
            $difference->draw("{$artefacts}/{$name}.difference.png");
            $changed[] = new ChangedPicture($taken->recipe, "{$artefacts}/{$name}.before.png", $after, "{$artefacts}/{$name}.difference.png", $difference->why());
        }

        $broken = [];
        foreach ($run->broken as $recipe) {
            $screen = null;
            if ($recipe->screen !== null && is_file($recipe->screen)) {
                $screen = "{$artefacts}/{$recipe->recipe->name}.broken.png";
                File::copy($recipe->screen, $screen);
            }
            $broken[] = new BrokenRecipe($recipe->recipe, $recipe->at, $recipe->said, $recipe->reason, $screen);
        }

        return new CheckedPictures($run->stockistCommit, $changed, $same, $broken);
    }
}

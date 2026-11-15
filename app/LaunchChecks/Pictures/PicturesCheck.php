<?php

namespace App\LaunchChecks\Pictures;

use App\LaunchChecks\Check;
use App\LaunchChecks\CheckContext;
use App\LaunchChecks\Findings;
use App\Pictures\InvalidRecipe;
use App\Pictures\PictureCheck;
use App\Pictures\Recipe;
use App\Pictures\StockistUnavailable;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Launch Check 12, Pictures (spec #30; ADR-0005): every Picture has a Recipe, every Recipe can
 * be read (its alt text never starting "Screenshot", "Image" or "Picture") and has its Picture,
 * and every Picture an Article shows can be shown. With CheckContext::$takePictures the Picture
 * Check retakes Pictures from the main project's checkout (main or the paired change, at the
 * Picture Date) and fails each one a reader would see differ, and each Recipe that broke:
 *
 * - `changed`: only the Pictures the pull request adds or changes since $picturesBase (CI);
 * - `all`: every Picture (the Release Check, against the version being released, and the Help
 *   Drift Report, against main).
 *
 * Client-tier Pictures pass the text check while they are retaken (#63's camera rule, a broken
 * Recipe when it fails). Files are named pictures/<file> whatever the folder is called.
 *
 * retakeEvery() checks another repository's folder of Pictures of Stockist screens the same way:
 * the Release Check retakes First Mile Manager's help's Pictures with it (ADR-0006).
 */
final readonly class PicturesCheck implements Check
{
    public const string RECIPE = 'pictures.recipe';

    public const string TAKEN = 'pictures.taken';

    public const string USED = 'pictures.used';

    public const string RETAKEN = 'pictures.retaken';

    public const string BROKEN = 'pictures.broken';

    public const string STOCKIST = 'pictures.stockist';

    public function __construct(private PictureCheck $pictureCheck) {}

    public function run(CheckContext $context, Findings $findings): void
    {
        $folder = rtrim($context->picturesPath ?? Config::string('help.pictures_path'), '/');
        $recipes = $this->recipes($folder, 'pictures', $findings);
        $this->usedPictures($context, $findings);

        if ($context->takePictures === null) {
            $findings->notRun('Pictures compared with what their Recipes take from Stockist (give --take-pictures)');

            return;
        }

        if ($context->takePictures === 'changed') {
            try {
                $changed = ChangedPictures::since($folder, (string) $context->picturesBase);
            } catch (RuntimeException $failure) {
                $findings->fail(self::STOCKIST, 'pictures', null, "Couldn't tell which Pictures changed since {$context->picturesBase}: {$failure->getMessage()}");

                return;
            }
            $recipes = array_values(array_filter($recipes, fn (Recipe $recipe): bool => in_array($recipe->name, $changed, true)));
        }
        if ($recipes === []) {
            return;
        }

        $this->retake($recipes, $folder, 'pictures', $context->mainProject->path, $context->pictureArtefacts ?? PictureCheck::defaultArtefacts(), $findings);
    }

    /**
     * Retakes every Picture in another repository's folder of Pictures of Stockist screens, in
     * this repository's format (First Mile Manager's help, for the Release Check: ADR-0006), from
     * the main project's checkout at $repository (its HEAD), and fails each one that differs,
     * each Recipe that broke or can't be read, and each Picture or Recipe missing its other half,
     * as Launch Check 12 does for this repository's own. Files are named "<$shownAs>/<file>";
     * $takeWith is added to each retake command the findings give, such as the folder to take into.
     *
     * @return list<Recipe> the Recipes it could read
     */
    public function retakeEvery(string $folder, string $shownAs, string $repository, string $artefacts, Findings $findings, string $takeWith = ''): array
    {
        $folder = rtrim($folder, '/');
        $recipes = $this->recipes($folder, $shownAs, $findings, $takeWith);
        if ($recipes !== []) {
            $this->retake($recipes, $folder, $shownAs, $repository, $artefacts, $findings, $takeWith);
        }

        return $recipes;
    }

    /**
     * Every Recipe in the folder that can be read; each problem in the rest, each Picture with
     * no Recipe and each Recipe with no Picture fail.
     *
     * @return list<Recipe>
     */
    private function recipes(string $folder, string $shownAs, Findings $findings, string $takeWith = ''): array
    {
        $recipes = [];
        foreach (glob("{$folder}/*.yaml") ?: [] as $path) {
            $file = basename($path);
            try {
                $recipe = Recipe::read($path, $file);
            } catch (InvalidRecipe $invalid) {
                foreach ($invalid->problems as $problem) {
                    $findings->fail(self::RECIPE, "{$shownAs}/{$file}", self::lineOf($path, $problem->field),
                        $problem->field === '' ? $problem->message : "{$problem->field}: {$problem->message}");
                }

                continue;
            }
            $recipes[] = $recipe;
            if (! is_file("{$folder}/{$recipe->name}.png")) {
                $findings->fail(self::TAKEN, "{$shownAs}/{$file}", null, "has no Picture ({$recipe->name}.png): take it with php artisan pictures:take {$recipe->name}{$takeWith}");
            }
        }

        foreach (glob("{$folder}/*.png") ?: [] as $path) {
            $name = basename($path, '.png');
            if (! is_file("{$folder}/{$name}.yaml")) {
                $findings->fail(self::RECIPE, "{$shownAs}/{$name}.png", null, "has no Recipe ({$name}.yaml), so the picture tool never took it: write its Recipe (pictures/README.md), then php artisan pictures:take {$name}{$takeWith}");
            }
        }

        return $recipes;
    }

    /** What a rebuild refuses about the Pictures Articles show, at the line that shows each. */
    private function usedPictures(CheckContext $context, Findings $findings): void
    {
        foreach ($context->contentProblems() as $problem) {
            if ($problem->field !== 'Picture') {
                continue;
            }
            preg_match('/\Aline (\d+): (.*)\z/s', $problem->message, $match);
            $findings->fail(self::USED, $problem->file, $match === [] ? null : (int) $match[1], $match[2] ?? $problem->message);
        }
    }

    /** @param  list<Recipe>  $recipes */
    private function retake(array $recipes, string $folder, string $shownAs, string $repository, string $artefacts, Findings $findings, string $takeWith = ''): void
    {
        $names = implode(', ', array_map(fn (Recipe $recipe): string => $recipe->name, $recipes));

        try {
            $checked = $this->pictureCheck->run($recipes, $folder, $artefacts, repository: $repository, ref: 'HEAD');
        } catch (StockistUnavailable $unavailable) {
            $findings->fail(self::STOCKIST, $shownAs, null, "The Pictures to check ({$names}) couldn't be retaken: {$unavailable->getMessage()}");

            return;
        }

        $where = CheckContext::displayPath($artefacts);
        $stockist = 'Stockist '.substr($checked->stockistCommit, 0, 12);
        foreach ($checked->changed as $changed) {
            // A Recipe with no Picture yet already fails as pictures.taken.
            if ($changed->before === null) {
                continue;
            }
            $name = $changed->recipe->name;
            $findings->fail(self::RETAKEN, "{$shownAs}/{$name}.png", null,
                "isn't what its Recipe takes from {$stockist} at the Picture Date: {$changed->why}. "
                ."Before, after and difference: {$name}.before.png, {$name}.after.png and {$name}.difference.png in {$where}. "
                ."If the screen changed, take it again with php artisan pictures:take {$name}{$takeWith}; Pictures are never edited by hand.");
        }
        foreach ($checked->broken as $broken) {
            $findings->fail(self::BROKEN, "{$shownAs}/{$broken->recipe->file}", self::lineOf("{$folder}/{$broken->recipe->file}", $broken->at),
                "{$broken->at} ({$broken->said}) can't be followed in {$stockist}: {$broken->reason}"
                .($broken->screen === null ? '' : '. The screen where it broke: '.basename($broken->screen)." in {$where}"));
        }
    }

    /** The line of a Recipe's field ("alt", or "step 2" for the second of its steps), or null. */
    private static function lineOf(string $path, string $field): ?int
    {
        $lines = preg_split('/\R/', (string) @file_get_contents($path)) ?: [];
        $step = preg_match('/\Astep (\d+)\z/', $field, $match) === 1 ? (int) $match[1] : null;
        $field = $step === null ? $field : 'steps';
        if (preg_match('/\A[a-z_]+\z/', $field) !== 1) {
            return null;
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/\A'.$field.'\s*:/', $line) !== 1) {
                continue;
            }
            if ($step === null) {
                return $index + 1;
            }
            $seen = 0;
            foreach (array_slice($lines, $index + 1, null, true) as $itemIndex => $item) {
                if (preg_match('/\A\S/', $item) === 1) {
                    break;
                }
                if (preg_match('/\A\s*-\s/', $item) === 1 && ++$seen === $step) {
                    return $itemIndex + 1;
                }
            }

            return $index + 1;
        }

        return null;
    }
}

<?php

namespace App\Pictures;

/**
 * The Picture Recipes in a pictures folder (pictures/<Picture Name>.yaml), read and checked
 * together so every problem in every Recipe is reported at once.
 */
final class Recipes
{
    /**
     * Every Recipe in $folder, or only those named, in name order.
     *
     * @param  list<string>  $names  Picture Names; empty for all
     * @return list<Recipe>
     *
     * @throws InvalidRecipe naming every problem in every Recipe read
     */
    public static function in(string $folder, array $names = []): array
    {
        $files = glob(rtrim($folder, '/').'/*.yaml') ?: [];
        sort($files);
        $problems = [];

        if ($names !== []) {
            $wanted = [];
            foreach (array_unique($names) as $name) {
                $file = rtrim($folder, '/')."/{$name}.yaml";
                if (in_array($file, $files, true)) {
                    $wanted[] = $file;
                } else {
                    $problems[] = new RecipeProblem("{$name}.yaml", '', "there is no Recipe called {$name} in {$folder}");
                }
            }
            $files = $wanted;
        }

        $recipes = [];
        foreach ($files as $file) {
            try {
                $recipes[] = Recipe::read($file);
            } catch (InvalidRecipe $invalid) {
                array_push($problems, ...$invalid->problems);
            }
        }

        if ($problems !== []) {
            throw new InvalidRecipe($problems);
        }

        return $recipes;
    }
}

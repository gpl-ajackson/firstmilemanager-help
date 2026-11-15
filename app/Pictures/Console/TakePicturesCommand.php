<?php

namespace App\Pictures\Console;

use App\Pictures\InvalidRecipe;
use App\Pictures\PictureTool;
use App\Pictures\Recipes;
use App\Pictures\StockistUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * php artisan pictures:take: takes Pictures from their Picture Recipes (pictures/README.md).
 * Every Recipe is checked first; then Stockist is fetched at the ref, installed and seeded at
 * the Picture Date (kept for the next run), and each Recipe is followed from a fresh copy.
 *
 * Prints one line per Recipe: `TAKEN <Picture Name> <device> <width>x<height> <path>`,
 * `BROKEN <file>: <step> (<as written>): <why>` or `INVALID <file>: <field>: <message>`.
 * Exits 0 when every Picture was taken, 1 when any Recipe is broken or invalid, and 2 when no
 * Stockist could be made to take them from.
 */
final class TakePicturesCommand extends Command
{
    protected $signature = 'pictures:take
        {names?* : Picture Names to take (default: every Recipe in the folder)}
        {--pictures= : The pictures folder of Recipes (default: config help.pictures_path)}
        {--into= : Where to save the Pictures (default: beside their Recipes)}
        {--stockist= : The Stockist repository: a git URL or a local checkout (default: config help.pictures.stockist_repository)}
        {--ref= : The Stockist branch, tag, commit or pull request ref (default: config help.pictures.stockist_ref, main)}';

    protected $description = 'Take Pictures by following their Picture Recipes through Stockist at the Picture Date';

    public function handle(PictureTool $tool): int
    {
        $folder = $this->stringOption('pictures') ?? Config::string('help.pictures_path');
        /** @var list<string> $names */
        $names = array_values((array) $this->argument('names'));

        try {
            $recipes = Recipes::in($folder, $names);
        } catch (InvalidRecipe $invalid) {
            foreach ($invalid->problems as $problem) {
                $this->line("INVALID {$problem}");
            }
            $this->line(count($invalid->problems).' '.Str::plural('problem', count($invalid->problems)).' in the Recipes; no Pictures taken.');

            return self::FAILURE;
        }

        if ($recipes === []) {
            $this->line("No Recipes in {$folder}; nothing to take.");

            return self::SUCCESS;
        }

        try {
            $run = $tool->take(
                $recipes,
                into: $this->stringOption('into') ?? $folder,
                repository: $this->stringOption('stockist'),
                ref: $this->stringOption('ref'),
                say: fn (string $line) => $this->line($line),
            );
        } catch (StockistUnavailable $unavailable) {
            $this->error('No Pictures taken: '.$unavailable->getMessage());

            return 2;
        }

        foreach ($run->taken as $picture) {
            $this->line("TAKEN {$picture->recipe->name} {$picture->recipe->device->value} {$picture->width}x{$picture->height} {$picture->path}");
        }
        foreach ($run->broken as $broken) {
            $this->line("BROKEN {$broken}");
            if ($broken->screen !== null) {
                $this->line("  The screen where it broke: {$broken->screen}");
            }
        }

        $this->line(count($run->taken).' '.Str::plural('Picture', count($run->taken)).' taken, '
            .count($run->broken).' '.Str::plural('Recipe', count($run->broken)).' broken, from Stockist '
            .substr($run->stockistCommit, 0, 12).' at '.PictureTool::pictureDate()->format('j F Y H:i T').'.');

        return $run->broken === [] ? self::SUCCESS : self::FAILURE;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

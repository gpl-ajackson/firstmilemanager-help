<?php

namespace App\Pictures\Console;

use App\Pictures\CheckedPictures;
use App\Pictures\InvalidRecipe;
use App\Pictures\PictureCheck;
use App\Pictures\PictureTool;
use App\Pictures\Recipes;
use App\Pictures\StockistUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * php artisan pictures:check: the Picture Check on its own (spec #30, ADR-0005). Retakes every
 * Picture (or those named) from Stockist at a ref, compares each with the Picture in the
 * pictures folder, and lists those a reader would see differ and the Recipes that broke. A
 * main-project pull request runs it against its own change (#65); the support site's pull
 * requests, the Release Check and the Help Drift Report run it as Launch Check 12
 * (help:launch-checks --take-pictures).
 *
 * Prints `CHANGED <Picture Name>.png: <what changed>` with its before, after and difference
 * images, `SAME <Picture Name>`, `BROKEN <file>: <step> (<as written>): <why>` and `INVALID
 * <file>: <field>: <message>`. Exits 0 when every Picture is the same, 1 when any changed or
 * any Recipe is broken or invalid, 2 when no Stockist could be made to take them from.
 */
final class CheckPicturesCommand extends Command
{
    /** The hidden first line of the Markdown, by which CI finds and updates its own comment. */
    public const string MARKER = '<!-- help-picture-check -->';

    protected $signature = 'pictures:check
        {names?* : Picture Names to check (default: every Recipe in the folder)}
        {--pictures= : The pictures folder of Pictures and Recipes (default: config help.pictures_path)}
        {--stockist= : The Stockist repository: a git URL or a local checkout (default: config help.pictures.stockist_repository)}
        {--ref= : The Stockist branch, tag, commit or pull request ref (default: config help.pictures.stockist_ref, main)}
        {--artefacts= : Where to leave before, after and difference images (default: storage/pictures/check)}
        {--markdown= : Also write the list as Markdown, for a pull request comment}';

    protected $description = 'Retake Pictures from Stockist at a ref and list each one that changed and each Recipe that broke';

    public function handle(PictureCheck $check): int
    {
        $folder = rtrim($this->stringOption('pictures') ?? Config::string('help.pictures_path'), '/');
        $artefacts = $this->stringOption('artefacts') ?? PictureCheck::defaultArtefacts();
        /** @var list<string> $names */
        $names = array_values((array) $this->argument('names'));

        try {
            $recipes = Recipes::in($folder, $names);
        } catch (InvalidRecipe $invalid) {
            foreach ($invalid->problems as $problem) {
                $this->line("INVALID {$problem}");
            }
            $this->line(count($invalid->problems).' '.Str::plural('problem', count($invalid->problems)).' in the Recipes; no Pictures checked.');

            return self::FAILURE;
        }
        if ($recipes === []) {
            $this->line("No Recipes in {$folder}; nothing to check.");

            return self::SUCCESS;
        }

        try {
            $checked = $check->run($recipes, $folder, $artefacts,
                repository: $this->stringOption('stockist'),
                ref: $this->stringOption('ref'),
                say: fn (string $line) => $this->line($line),
            );
        } catch (StockistUnavailable $unavailable) {
            $this->error('No Pictures checked: '.$unavailable->getMessage());

            return 2;
        }

        foreach ($checked->changed as $changed) {
            $this->line("CHANGED {$changed}");
            foreach (['before' => $changed->before, 'after' => $changed->after, 'difference' => $changed->difference] as $label => $path) {
                if ($path !== null) {
                    $this->line("  {$label}: {$path}");
                }
            }
        }
        foreach ($checked->same as $name) {
            $this->line("SAME {$name}");
        }
        foreach ($checked->broken as $broken) {
            $this->line("BROKEN {$broken}");
            if ($broken->screen !== null) {
                $this->line("  The screen where it broke: {$broken->screen}");
            }
        }
        $summary = $this->summary($checked);
        $checked->passed() ? $this->info($summary) : $this->error($summary);

        $markdown = $this->stringOption('markdown');
        if ($markdown !== null) {
            file_put_contents($markdown, $this->markdown($checked, $summary));
        }

        return $checked->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function summary(CheckedPictures $checked): string
    {
        return count($checked->changed).' '.Str::plural('Picture', count($checked->changed)).' changed, '
            .count($checked->same).' the same, '
            .count($checked->broken).' '.Str::plural('Recipe', count($checked->broken)).' broken, in Stockist '
            .substr($checked->stockistCommit, 0, 12).' at '.PictureTool::pictureDate()->format('j F Y H:i T').'.';
    }

    /** The list as a pull request comment; the images are in the run's artefacts, by file name. */
    private function markdown(CheckedPictures $checked, string $summary): string
    {
        $lines = [self::MARKER, '## Picture Check', '', ($checked->passed() ? '**No Picture changed.** ' : '').$summary, ''];
        if ($checked->changed !== []) {
            $lines[] = '### Pictures this change alters';
            $lines[] = '';
            foreach ($checked->changed as $changed) {
                $files = array_map(basename(...), array_filter([$changed->before, $changed->after, $changed->difference]));
                $lines[] = "- `{$changed->recipe->name}` ({$changed->why}), used by ".implode(', ', array_map(fn (string $article): string => "`{$article}`", $changed->recipe->articles))
                    .'. Images: '.implode(', ', array_map(fn (string $file): string => "`{$file}`", $files));
            }
            $lines[] = '';
        }
        if ($checked->broken !== []) {
            $lines[] = '### Recipes that no longer work';
            $lines[] = '';
            foreach ($checked->broken as $broken) {
                $lines[] = "- `{$broken->recipe->file}` {$broken->at} (`{$broken->said}`): ".htmlspecialchars($broken->reason, ENT_NOQUOTES)
                    .($broken->screen === null ? '' : '. Screen: `'.basename($broken->screen).'`');
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

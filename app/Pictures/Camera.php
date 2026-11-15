<?php

namespace App\Pictures;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Follows one Recipe through a running Stockist in headless Chromium (Playwright, found on
 * NODE_PATH as the layout tests find it) and saves its Picture: node/take-picture.cjs does the
 * following; this side hands it the Recipe and reads back what happened.
 *
 * A Client-tier Recipe (ClientTier) has the team's owner rename the team "Your 3PL" in Team
 * Settings first, crops Stockist's top bar out, and is broken at "text check" when the Picture
 * shows Stockist's or the demo 3PL's name; the Picture is then kept as the screen where it broke.
 */
final class Camera
{
    /** Seconds one Recipe may take, from signing in to saving the Picture, on a busy machine too. */
    private const int TIMEOUT = 300;

    /**
     * @param  TeamOwner|null  $owner  who renames the team for a Client-tier Recipe; staff Recipes don't use it
     *
     * @throws RuntimeException when the camera itself fails, which says nothing about the Recipe
     */
    public static function take(Recipe $recipe, string $baseUrl, string $email, string $moment, string $output, string $brokenOutput, ?TeamOwner $owner = null): TakenPicture|BrokenRecipe
    {
        if ($recipe->isClientTier() && $owner === null) {
            return new BrokenRecipe($recipe, 'team name', 'Team Settings › Team Name: '.ClientTier::TEAM_NAME, "{$recipe->signsInAs} is in no team with an owner, so no one can rename it ".ClientTier::TEAM_NAME);
        }

        $job = [
            'baseUrl' => $baseUrl,
            'output' => $output,
            'brokenOutput' => $brokenOutput,
            'device' => ['width' => $recipe->device->width(), 'height' => $recipe->device->height()],
            'moment' => $moment,
            'email' => $email,
            'password' => StockistCopy::PASSWORD,
            'signsInAs' => $recipe->signsInAs,
            'startsOn' => $recipe->startsOn,
            'steps' => array_map(fn (RecipeStep $step): array => $step->forCamera(), $recipe->steps),
            'keep' => $recipe->keep,
            'outline' => $recipe->outline,
            'clientTier' => $recipe->isClientTier() && $owner !== null ? [
                'teamName' => ClientTier::TEAM_NAME,
                'team' => $owner->team,
                'owner' => ['name' => $owner->name, 'email' => $owner->email],
            ] : null,
        ];

        $result = Process::timeout(self::TIMEOUT)
            ->env(['NODE_PATH' => self::nodePath()])
            ->run(['node', __DIR__.'/node/take-picture.cjs', (string) json_encode($job, JSON_THROW_ON_ERROR)]);
        if ($result->failed()) {
            throw new RuntimeException("The camera failed on {$recipe->file}: ".trim($result->errorOutput()));
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        /** @var array{taken: bool, width?: int, height?: int, shows?: list<string>, at?: string, said?: string, reason?: string} $taken */
        $taken = json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);

        if ($taken['taken']) {
            $shows = $taken['shows'] ?? [];
            $problem = $recipe->isClientTier() ? ClientTier::problem($shows) : null;
            if ($problem !== null) {
                File::move($output, $brokenOutput);

                return new BrokenRecipe($recipe, 'text check', ClientTier::RULE, $problem, $brokenOutput);
            }

            return new TakenPicture($recipe, $output, (int) ($taken['width'] ?? 0), (int) ($taken['height'] ?? 0), $shows);
        }

        return new BrokenRecipe($recipe, (string) ($taken['at'] ?? ''), (string) ($taken['said'] ?? ''), (string) ($taken['reason'] ?? ''), is_file($brokenOutput) ? $brokenOutput : null);
    }

    /** Where Node finds Playwright: NODE_PATH when set (CI, cloud sessions), else npm's global folder. */
    private static function nodePath(): string
    {
        $set = getenv('NODE_PATH');
        if (is_string($set) && $set !== '') {
            return $set;
        }

        return trim(Process::run(['npm', 'root', '--global'])->output());
    }
}

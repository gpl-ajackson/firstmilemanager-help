<?php

namespace App\Pictures;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use PDO;

/**
 * The picture tool (spec #30 seam 3, ADR-0005): takes each Recipe's Picture from Stockist at
 * a ref, seeded and held at the Picture Date. This is the entry other modules call: the
 * pictures:take command and the Picture Check (#64). Client-tier Recipes (`client-`) are taken
 * as ClientTier says: team renamed "Your 3PL", top bar left out, text checked.
 *
 *     $run = app(PictureTool::class)->take(Recipes::in($folder), into: $folder, ref: 'main');
 *     $run->taken;   // TakenPicture: Recipe, path, width, height
 *     $run->broken;  // BrokenRecipe: Recipe, the step that failed, why, and the screen there
 *
 * Not final: it is the seam at Stockist's edge, so fast tests of its callers (the Release
 * Check) bind a stand-in that "takes" fixture images instead of installing Stockist
 * (tests/Support/FixedPictureTool.php). tests/Pictures takes them for real.
 */
class PictureTool
{
    /**
     * The moment every Picture is taken at (config help.pictures.date): the second Wednesday
     * of January at 10:30 UK time, moved each January.
     */
    public static function pictureDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(Config::string('help.pictures.date'));
    }

    /**
     * @param  list<Recipe>  $recipes
     * @param  string  $into  the folder each <Picture Name>.png is saved in
     * @param  Closure(string): void|null  $say  progress, one line at a time
     *
     * @throws StockistUnavailable
     */
    public function take(array $recipes, string $into, ?string $repository = null, ?string $ref = null, ?Closure $say = null): PictureRun
    {
        $say ??= function (string $line): void {};
        $workPath = Config::string('help.pictures.work_path');
        File::ensureDirectoryExists($workPath);
        File::ensureDirectoryExists($into);

        // One run at a time per work folder: they share the installs and the seeded copy.
        $lock = fopen("{$workPath}/.lock", 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new StockistUnavailable("Couldn't lock {$workPath}/.lock.");
        }

        try {
            $stockist = $this->stockist($workPath, $repository, $ref, $say);

            $taken = [];
            $broken = [];
            foreach ($recipes as $recipe) {
                $say("Taking {$recipe->name}.");
                $result = $this->takeOne($stockist, $recipe, $into, $workPath);
                $result instanceof TakenPicture ? $taken[] = $result : $broken[] = $result;
            }

            return new PictureRun($stockist->commit, $taken, $broken);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Stockist at the ref, installed and seeded in $workPath (StockistCopy::prepare), from this
     * help centre's settings and database connection.
     *
     * @param  Closure(string): void  $say
     *
     * @throws StockistUnavailable
     */
    public function stockist(string $workPath, ?string $repository, ?string $ref, Closure $say): StockistCopy
    {
        $connection = Config::array('database.connections.pgsql');

        return StockistCopy::prepare(
            repository: $repository ?? Config::string('help.pictures.stockist_repository'),
            ref: $ref ?? Config::string('help.pictures.stockist_ref'),
            moment: self::pictureDate(),
            workPath: $workPath,
            databasePrefix: Config::string('help.pictures.database_prefix'),
            postgres: [
                'host' => is_string($connection['host'] ?? null) ? $connection['host'] : '127.0.0.1',
                'port' => is_numeric($connection['port'] ?? null) ? (int) $connection['port'] : 5432,
                'username' => is_string($connection['username'] ?? null) ? $connection['username'] : 'postgres',
                'password' => is_string($connection['password'] ?? null) ? $connection['password'] : '',
            ],
            offlineFonts: Config::boolean('help.pictures.offline_fonts'),
            say: $say,
        );
    }

    private function takeOne(StockistCopy $stockist, Recipe $recipe, string $into, string $workPath): TakenPicture|BrokenRecipe
    {
        File::ensureDirectoryExists("{$workPath}/broken");
        $working = "{$workPath}/taking-{$recipe->name}.png";
        $brokenScreen = "{$workPath}/broken/{$recipe->name}.png";
        File::delete([$working, $brokenScreen]);

        $result = $stockist->withFreshCopy(function (string $baseUrl, PDO $copy) use ($stockist, $recipe, $working, $brokenScreen): TakenPicture|BrokenRecipe {
            $people = $copy->prepare('select email from users where name = ?');
            $people->execute([$recipe->signsInAs]);
            $emails = $people->fetchAll(PDO::FETCH_COLUMN);
            if (count($emails) !== 1) {
                return new BrokenRecipe($recipe, 'signs_in_as', "signs_in_as: {$recipe->signsInAs}", $emails === []
                    ? "no one in the Trentside demo is called {$recipe->signsInAs}"
                    : count($emails)." people in the Trentside demo are called {$recipe->signsInAs}");
            }

            return Camera::take($recipe, $baseUrl, (string) $emails[0], $stockist->moment->toIso8601String(), $working, $brokenScreen, $recipe->isClientTier() ? $this->teamOwner($copy, $recipe) : null);
        });

        if ($result instanceof BrokenRecipe) {
            File::delete($working);

            return $result;
        }

        $path = rtrim($into, '/')."/{$recipe->name}.png";
        File::move($working, $path);

        return new TakenPicture($recipe, $path, $result->width, $result->height, $result->shows);
    }

    /**
     * The owner of the team the Recipe's person is working in, who renames it "Your 3PL" for a
     * Client-tier Picture; null when there is none (the camera then reports the Recipe broken).
     */
    private function teamOwner(PDO $copy, Recipe $recipe): ?TeamOwner
    {
        $owners = $copy->prepare('select teams.name as team, owners.name, owners.email from users
            join teams on teams.id = users.current_team_id
            join users as owners on owners.id = teams.user_id
            where users.name = ?');
        $owners->execute([$recipe->signsInAs]);
        /** @var array{team: string, name: string, email: string}|false $owner */
        $owner = $owners->fetch(PDO::FETCH_ASSOC);

        return $owner === false ? null : new TeamOwner((string) $owner['team'], (string) $owner['name'], (string) $owner['email']);
    }
}

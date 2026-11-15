<?php

namespace Tests\Support;

use App\Pictures\BrokenRecipe;
use App\Pictures\Camera;
use App\Pictures\PictureTool;
use App\Pictures\Recipe;
use App\Pictures\TakenPicture;
use App\Pictures\TeamOwner;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A fake Stockist for the picture tool's fast tests: the camera follows a Recipe through it in
 * headless Chromium exactly as it does through the real one, in seconds and with no Stockist
 * checkout, install or seed (those are the Pictures suite's, tests/Pictures).
 *
 *     $stockist = FakeStockist::start()->page('Your account', '<h1>Hebden Botanicals Ltd</h1>');
 *     $result = $stockist->take(recipes()->recipe('client-your-account', [...]), 'client-your-account');
 *
 * It has a sign-in screen, a top bar painted pure red (TOP_BAR_RED) with the "Stockist WMS"
 * logo, the team's tenant menu and a menu item per page, and Team Settings, where the team's
 * owner (OWNER) renames the team. "{team}" in a page shows the team's current name, first TEAM.
 * See fake-stockist/router.php.
 */
final class FakeStockist
{
    public const string TEAM = 'Trentside Logistics';

    public const string OWNER_NAME = 'Sarah Whitfield';

    public const string OWNER_EMAIL = 'sarah.whitfield@trentside.example';

    /** The top bar's colour, which no page uses: any pixel of it in a Picture is the top bar. */
    public const array TOP_BAR_RED = [255, 0, 0];

    /** The top bar's height in CSS pixels. */
    public const int TOP_BAR_HEIGHT = 64;

    /** @var list<self> */
    private static array $started = [];

    private function __construct(
        private readonly string $state,
        public readonly string $url,
        private readonly InvokedProcess $server,
    ) {}

    public static function start(): self
    {
        $state = FixtureRecipes::folder('help-fake-stockist');
        File::put("{$state}/team.txt", self::TEAM);
        File::put("{$state}/pages.json", json_encode(['Dashboard' => '<h1>Dashboard</h1><p>Welcome back.</p>'], JSON_THROW_ON_ERROR));

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        Assert::assertNotFalse($socket, 'No free port for the fake Stockist.');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $url = 'http://'.$name;

        $server = Process::env(['FAKE_STOCKIST_STATE' => $state, 'PHP_CLI_SERVER_WORKERS' => '4'])
            ->forever()
            ->start([PHP_BINARY, '-S', $name, __DIR__.'/fake-stockist/router.php']);

        $fake = new self($state, $url, $server);
        self::$started[] = $fake;
        $fake->waitUntilServing();

        return $fake;
    }

    /** Stops every fake Stockist this test started (TestCase::tearDown calls it). */
    public static function stopAll(): void
    {
        foreach (self::$started as $fake) {
            $fake->server->signal(SIGTERM);
            $fake->server->wait();
        }
        self::$started = [];
    }

    /**
     * Adds a page, reached from the top bar by its menu item. A page with `$answersAfterMs` takes
     * that long to answer, as a busy server does.
     */
    public function page(string $menuItem, string $body, int $answersAfterMs = 0): self
    {
        /** @var array<string, string> $pages */
        $pages = json_decode((string) File::get("{$this->state}/pages.json"), true, flags: JSON_THROW_ON_ERROR);
        $pages[$menuItem] = $body;
        File::put("{$this->state}/pages.json", json_encode($pages, JSON_THROW_ON_ERROR));
        if ($answersAfterMs > 0) {
            File::put("{$this->state}/slow-".md5($menuItem), (string) $answersAfterMs);
        }

        return $this;
    }

    /** Takes Team Settings away from the tenant menu, as a Stockist that moved it would. */
    public function withoutTeamSettings(): self
    {
        File::put("{$this->state}/no-team-settings", '');

        return $this;
    }

    /** The team's name now. */
    public function teamName(): string
    {
        return File::get("{$this->state}/team.txt");
    }

    /**
     * Every time the team was renamed through Team Settings: who was signed in, and the new name.
     *
     * @return list<array{by: string, name: string}>
     */
    public function renames(): array
    {
        if (! is_file("{$this->state}/renames.log")) {
            return [];
        }

        /** @var list<array{by: string, name: string}> */
        return array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", File::get("{$this->state}/renames.log")))),
        );
    }

    /**
     * Follows the Recipe <name>.yaml in $recipes through this Stockist with the picture tool's
     * camera, signed in as its person, as PictureTool does for each Recipe on a fresh copy.
     */
    public function take(FixtureRecipes $recipes, string $name): TakenPicture|BrokenRecipe
    {
        $recipe = Recipe::read($recipes->path()."/{$name}.yaml");
        $into = FixtureRecipes::folder();

        return Camera::take(
            $recipe,
            $this->url,
            strtolower(str_replace(' ', '.', $recipe->signsInAs)).'@example.test',
            PictureTool::pictureDate()->toIso8601String(),
            "{$into}/{$name}.png",
            "{$into}/broken-{$name}.png",
            new TeamOwner(self::TEAM, self::OWNER_NAME, self::OWNER_EMAIL),
        );
    }

    private function waitUntilServing(): void
    {
        $until = microtime(true) + 10;
        while (microtime(true) < $until) {
            try {
                if (Http::timeout(2)->get("{$this->url}/login")->successful()) {
                    return;
                }
            } catch (Throwable) {
                // Not listening yet.
            }
            usleep(100_000);
        }
        Assert::fail("The fake Stockist didn't start: ".$this->server->errorOutput());
    }
}

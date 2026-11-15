<?php

namespace Tests\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * A small content folder for one test, laid out as the real one (content/), published through
 * the same rebuild a deploy runs.
 *
 *     content()
 *         ->article('receive-a-shipment', ['title' => 'Receive a shipment'], "## Steps\n...")
 *         ->article('a-draft', ['status' => 'Draft'])
 *         ->publish();
 *
 * Every Article gets valid header data by default; pass only the fields a test is about.
 * A field set to null is left out of the file. file() writes any file as given, for header
 * data a writer could get wrong in ways an array can't express.
 *
 * Pictures go in a pictures folder of their own beside it, as pictures/ sits beside content/:
 *
 *     content()->picture('goods-in-scan-delivery')->article('x', [], "![](picture:goods-in-scan-delivery)")
 */
final class FixtureContent
{
    /** The header data every fixture Article starts from: a valid, Published, Staff How-to. */
    public const DEFAULT_HEADER = [
        'title' => null, // made from the Article Name
        'summary' => 'How to do one task in Stockist, in one sentence.',
        'type' => 'How-to',
        'product_area' => 'Inbound',
        'audience_tier' => 'Staff',
        'owner' => 'gpl-ajackson',
        'status' => 'Published',
        'checked_on' => '2026-10-24',
        'next_review_on' => '2027-04-24',
        'team_roles' => ['Admin'],
        'source_references' => ['ADR-0001'],
        'related_articles' => ['example-concept', 'example-reference'],
    ];

    /**
     * Fields added by type or tier when a test doesn't give them, so any type and tier is valid
     * by default: a Getting-started Article's place in the path, a Troubleshooting Article's
     * messages, and on a Client-tier Article no Team Roles, a summary that doesn't name Stockist
     * and Client-tier related articles.
     */
    private const TYPE_DEFAULTS = [
        'Getting-started' => ['path_position' => 1],
        'Troubleshooting' => ['messages' => ['Location is quarantined']],
    ];

    /** The default related articles of a Client-tier fixture Article (it links only to Client-tier ones). */
    private const CLIENT_EXAMPLES = ['example-client-concept', 'example-client-reference', 'example-client-overview'];

    /** A Picture's width in pixels as the picture tool takes it: double density. */
    public const PICTURE_WIDTHS = ['desk' => 2560, 'handheld' => 720];

    /** @var list<string> */
    private static array $made = [];

    private function __construct(private readonly string $path) {}

    public static function make(): self
    {
        $path = sys_get_temp_dir().'/help-content-'.getmypid().'-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($path.'/articles');
        File::ensureDirectoryExists($path.'-pictures');
        self::$made[] = $path;
        self::$made[] = $path.'-pictures';

        return (new self($path))->facts();
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
     * Writes content/articles/<name>.md with default header data overridden by $header.
     *
     * @param  array<string, mixed>  $header
     */
    public function article(string $name, array $header = [], ?string $body = null): self
    {
        $type = $header['type'] ?? self::DEFAULT_HEADER['type'];
        $tierDefaults = ($header['audience_tier'] ?? null) === 'Client'
            ? ['summary' => 'How to do one task with your courier, in one sentence.', 'team_roles' => null, 'related_articles' => array_slice(self::CLIENT_EXAMPLES, 0, 2)]
            : [];
        $header = array_merge(
            self::DEFAULT_HEADER,
            ['title' => Str::ucfirst(str_replace('-', ' ', $name))],
            is_string($type) ? self::TYPE_DEFAULTS[$type] ?? [] : [],
            $tierDefaults,
            $header,
        );
        $header = array_filter($header, fn (mixed $value): bool => $value !== null);

        // Dates are written unquoted, as writers write them.
        $yaml = preg_replace("/'(\d{4}-\d{2}-\d{2})'/", '$1', Yaml::dump($header));

        return $this->file("articles/{$name}.md", "---\n{$yaml}---\n\n".($body ?? "Do the task.\n"));
    }

    /** The header data every fixture Glossary Entry starts from (its term and tier are added). */
    public const DEFAULT_ENTRY_HEADER = [
        'where_you_see_it' => 'On the Inventory menu.',
        'checked_on' => '2026-10-24',
    ];

    /**
     * Writes content/glossary/<name>.md, a staff Glossary Entry, with default header data
     * overridden by $header. The term is made from the name ("stock-container" is "Stock
     * Container"), so the entry's address follows it.
     *
     * @param  array<string, mixed>  $header
     */
    public function glossaryEntry(string $name, array $header = [], ?string $body = null): self
    {
        return $this->entry('glossary', 'Staff', $name, $header, $body);
    }

    /**
     * Writes content/client-glossary/<name>.md, a Client Glossary entry.
     *
     * @param  array<string, mixed>  $header
     */
    public function clientGlossaryEntry(string $name, array $header = [], ?string $body = null): self
    {
        return $this->entry('client-glossary', 'Client', $name, $header, $body);
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function entry(string $folder, string $tier, string $name, array $header, ?string $body): self
    {
        $header = array_merge(
            ['term' => Str::title(str_replace('-', ' ', $name)), 'audience_tier' => $tier],
            self::DEFAULT_ENTRY_HEADER,
            $header,
        );
        $header = array_filter($header, fn (mixed $value): bool => $value !== null);
        $yaml = preg_replace("/'(\d{4}-\d{2}-\d{2})'/", '$1', Yaml::dump($header, 3));

        return $this->file("{$folder}/{$name}.md", "---\n{$yaml}---\n\n".($body ?? "A plain sentence about the term.\n"));
    }

    /** One change in a fixture Release Note, as a Change Summary gives it. */
    public const DEFAULT_CHANGE = [
        'product_area' => 'Inbound',
        'team_roles' => ['Admin'],
        'affects_clients' => false,
        'text' => 'You can now book in a delivery from the dock.',
    ];

    /**
     * Writes content/release-notes/<date>.md, one FMM Release's Release Note: a summary and
     * its changes (by default one, DEFAULT_CHANGE), overridden by $header; a field set to null is
     * left out. Each change in $header['changes'] starts from DEFAULT_CHANGE.
     *
     * @param  array<string, mixed>  $header
     */
    public function releaseNote(string $date, array $header = [], ?string $body = null): self
    {
        $header = array_merge(['summary' => 'What changed in Stockist on '.$date.'.', 'changes' => [[]]], $header);
        if (is_array($header['changes'])) {
            $header['changes'] = array_map(
                fn (mixed $change): mixed => is_array($change) ? array_filter([...self::DEFAULT_CHANGE, ...$change], fn (mixed $value): bool => $value !== null) : $change,
                $header['changes'],
            );
        }
        $header = array_filter($header, fn (mixed $value): bool => $value !== null);

        return $this->file("release-notes/{$date}.md", "---\n".Yaml::dump($header, 4, 2)."---\n".($body !== null ? "\n{$body}" : ''));
    }

    /** The header data every fixture Change Summary starts from. */
    public const DEFAULT_CHANGE_SUMMARY = [
        'product_area' => 'Inbound',
        'team_roles' => ['Admin'],
        'affects_clients' => false,
    ];

    /**
     * Writes content/next-release/<name>.md, a Change Summary waiting for the next Stockist
     * Release, with default header data overridden by $header (a field set to null is left out).
     *
     * @param  array<string, mixed>  $header
     */
    public function changeSummary(string $name, array $header = [], ?string $body = null): self
    {
        $header = array_filter([...self::DEFAULT_CHANGE_SUMMARY, ...$header], fn (mixed $value): bool => $value !== null);

        return $this->file("next-release/{$name}.md", "---\n".Yaml::dump($header, 3, 2)."---\n\n".($body ?? "You can now book in a delivery from the dock.\n"));
    }

    /** The help centre's facts every fixture folder starts with, as the real content/facts.yaml has them. */
    public const DEFAULT_FACTS = [
        'name' => 'First Mile Manager',
        'description' => 'First Mile Manager is software for small couriers and hauliers that collect from local businesses.',
        'stage' => 'Early access',
        'url' => 'https://firstmilemanager.co.uk',
    ];

    /**
     * Writes facts.yaml: the default facts overridden by $facts (a fact set to null is left out).
     * Every fixture folder has them from the start, as the rebuild needs them.
     *
     * @param  array<string, mixed>  $facts
     */
    public function facts(array $facts = []): self
    {
        $facts = array_filter([...self::DEFAULT_FACTS, ...$facts], fn (mixed $value): bool => $value !== null);

        return $this->file('facts.yaml', Yaml::dump($facts));
    }

    /**
     * Writes the Articles that fixture Articles are related to by default, when any Article still
     * names them and they aren't there, so the Links check (Launch Check 3) finds every default
     * related article. They are Reference Articles in the default Product Area, related to each
     * other: three for Staff and three Client-tier ones for Client-tier Articles. LaunchCheckRun calls it; a rebuild test that wants them calls it too.
     */
    public function withDefaultRelatedArticles(): self
    {
        $this->writeExamples([...self::DEFAULT_HEADER['related_articles'], 'example-overview'], []);

        return $this->writeExamples(self::CLIENT_EXAMPLES, ['audience_tier' => 'Client']);
    }

    /**
     * @param  list<string>  $examples
     * @param  array<string, mixed>  $header
     */
    private function writeExamples(array $examples, array $header): self
    {
        $named = false;
        foreach (glob($this->path.'/articles/*.md') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            $named = $named || array_any($examples, fn (string $example): bool => str_contains($contents, $example));
        }
        if (! $named) {
            return $this;
        }

        foreach ($examples as $example) {
            if (! is_file("{$this->path}/articles/{$example}.md")) {
                $others = array_values(array_diff($examples, [$example]));
                $this->article($example, ['type' => 'Reference', 'related_articles' => $others, ...$header], "A table of examples.\n");
            }
        }

        return $this;
    }

    /**
     * Writes product-areas.yaml: the app's main menus, each with the main project's translation
     * key for its menu label (Launch Check 1).
     *
     * @param  array<string, string>  $areas  Product Area => menu translation key
     */
    public function productAreas(array $areas): self
    {
        $entries = array_map(fn (string $name, string $menu): string => "- name: {$name}\n  menu: {$menu}\n", array_keys($areas), $areas);

        return $this->file('product-areas.yaml', implode('', $entries));
    }

    /**
     * Writes article-owners.yaml: the GitHub logins of the people who may own Articles.
     *
     * @param  list<string>  $logins
     */
    public function articleOwners(array $logins): self
    {
        return $this->file('article-owners.yaml', Yaml::dump($logins));
    }

    /**
     * Writes term-status.yaml: each GLOSSARY.md term's Term Status (Launch Check 4). Each
     * value is its fields, such as ['status' => 'Published', 'product_area' => 'Inventory',
     * 'fingerprint' => '...']; a null field is left out.
     *
     * @param  array<string, array<string, mixed>>  $statuses  term => its fields
     */
    public function termStatus(array $statuses): self
    {
        $statuses = array_map(fn (array $fields): array => array_filter($fields, fn (mixed $value): bool => $value !== null), $statuses);

        return $this->file('term-status.yaml', Yaml::dump($statuses, 2));
    }

    /**
     * Writes ignored-words.yaml: the Content Owner's Ignored Words (Launch Check 5).
     *
     * @param  list<string>  $words
     */
    public function ignoredWords(array $words): self
    {
        return $this->file('ignored-words.yaml', Yaml::dump($words));
    }

    /**
     * Writes findability-set.yaml: each reader's question with the address of its named answer
     * (an Article, an Article's Section or a Glossary Entry), as Launch Check 10 reads it.
     *
     * @param  array<string, string>  $questions  question => /articles/<name>[#<Section Name>], /glossary/<term> or /client-glossary/<term>
     */
    public function findabilitySet(array $questions): self
    {
        $entries = array_map(fn (string $question, string $answer): array => compact('question', 'answer'), array_keys($questions), $questions);

        return $this->file('findability-set.yaml', Yaml::dump($entries, 2));
    }

    /** Writes any file into the folder, exactly as given. */
    public function file(string $relativePath, string $contents): self
    {
        File::ensureDirectoryExists(dirname($this->path.'/'.$relativePath));
        File::put($this->path.'/'.$relativePath, $contents);

        return $this;
    }

    /**
     * Writes a Picture and its Recipe into the pictures folder, as the picture tool leaves
     * them: pictures/<name>.png, double density, and pictures/<name>.yaml. The Recipe is a
     * valid one (FixtureRecipes::DEFAULT_RECIPE) with alt text made from the name, overridden
     * by $recipe; a field set to null is left out. $width overrides the device's width, for a
     * Picture not taken by the tool.
     *
     * @param  array<string, mixed>  $recipe
     */
    public function picture(string $name, array $recipe = [], ?int $width = null, int $height = 600, string $shade = '#F2F3F5'): self
    {
        $recipe = array_filter([
            ...FixtureRecipes::DEFAULT_RECIPE,
            'alt' => 'The '.str_replace('-', ' ', $name).' screen, with the one thing to look at outlined.',
            ...$recipe,
        ], fn (mixed $value): bool => $value !== null);
        $device = is_string($recipe['device'] ?? null) ? $recipe['device'] : 'desk';

        return $this->pictureFile("{$name}.png", self::picturePng($width ?? self::PICTURE_WIDTHS[$device] ?? 2560, $height, $shade))
            ->pictureFile("{$name}.yaml", Yaml::dump($recipe, 4, 2));
    }

    /** A plain Picture: a shade with one dark outlined box, as the picture tool's would be at that size. */
    public static function picturePng(int $width, int $height = 600, string $shade = '#F2F3F5'): string
    {
        $image = imagecreatetruecolor($width, $height);
        [$red, $green, $blue] = sscanf($shade, '#%02x%02x%02x');
        imagefill($image, 0, 0, (int) imagecolorallocate($image, (int) $red, (int) $green, (int) $blue));
        imagerectangle($image, 40, 40, 400, 200, (int) imagecolorallocate($image, 18, 21, 28));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Writes any file into the pictures folder, exactly as given. */
    public function pictureFile(string $relativePath, string $contents): self
    {
        File::ensureDirectoryExists(dirname($this->picturesPath().'/'.$relativePath));
        File::put($this->picturesPath().'/'.$relativePath, $contents);

        return $this;
    }

    /** The line of a file in the folder where $text first appears, counted from 1, as the rebuild reports it. */
    public function lineOf(string $relativePath, string $text): int
    {
        $contents = (string) file_get_contents($this->path.'/'.$relativePath);
        $at = strpos($contents, $text);
        Assert::assertNotFalse($at, "{$relativePath} has no \"{$text}\"");

        return substr_count($contents, "\n", 0, $at) + 1;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function picturesPath(): string
    {
        return $this->path.'-pictures';
    }

    /**
     * Runs the rebuild over this folder and returns its exit code and output, for tests about a
     * rebuild that should fail.
     *
     * @return array{exitCode: int, output: string}
     */
    public function rebuild(): array
    {
        $exitCode = Artisan::call('help:rebuild', ['--content' => $this->path, '--pictures' => $this->picturesPath()]);

        return ['exitCode' => $exitCode, 'output' => Artisan::output()];
    }

    /** Publishes this folder as a deploy does, failing the test if the rebuild fails. */
    public function publish(): self
    {
        ['exitCode' => $exitCode, 'output' => $output] = $this->rebuild();

        Assert::assertSame(Command::SUCCESS, $exitCode, "The content rebuild failed:\n{$output}");

        return $this;
    }
}

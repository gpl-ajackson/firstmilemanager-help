<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * A small folder shaped like a checkout of the main project (gpl-ajackson/firstmilemanager), for the
 * Launch Checks' content checks (spec #30, Testing Decisions). It holds only what the checks
 * read: the English language files behind the app's menus and its Team Role names, plans in
 * .junie/plans/ and ADRs in docs/adr/. Its issues (specs) live on GitHub, not in a checkout, so
 * they are kept here and LaunchCheckRun serves them from a faked GitHub API.
 *
 *     mainProject()->menu('outbound.cluster.title', 'Outbound')->withoutMenu('inbound.cluster.title');
 *     mainProject()->plan('.junie/plans/Inbound/receiving.md', "- /articles/receive-a-delivery")
 *         ->adr(10)->issue(87, 'Spec: Inbound Receiving', "## Help content\n\n- /articles/x", closed: true);
 *
 * By default it has today's menus and Team Roles, as in Stockist's lang/en, and ADR-0001 (which
 * fixture Articles cite by default).
 */
final class FixtureMainProject
{
    /** Today's main menus in Stockist: each Filament cluster's navigation label key and label. */
    public const MENUS = [
        'warehouses.cluster.title' => 'Warehouses',
        'stock_containers.cluster.title' => 'Stock Containers',
        'products.cluster.title' => 'Products',
        'clients.cluster.title' => 'Clients',
        'inbound.cluster.title' => 'Inbound',
        'inventory.cluster.title' => 'Inventory',
    ];

    /** First Mile Manager's role enums' labels (app/Domain/Access), keyed by case name. */
    public const TEAM_ROLES = [
        'Admin' => 'Admin',
        'Operative' => 'Warehouse Operative',
        'ClientUser' => 'Client User',
        'ClientAdmin' => 'Client Account Administrator',
    ];

    /** @var list<string> */
    private static array $made = [];

    /** @var array<string, array<mixed>> language file name => its array */
    private array $lang = [];

    /** @var array<int, array{number: int, title: string, body: string, state: string, state_reason: ?string, pull_request?: array<string, string>}> */
    private array $issues = [];

    /** @var array<int, string> issue number => when its description was last edited (ISO 8601) */
    private array $edits = [];

    /** @var list<array{number: int, title: string, body: string, merged_at: string, author: string}> */
    private array $mergedPullRequests = [];

    private function __construct(private readonly string $path) {}

    public static function make(): self
    {
        $path = sys_get_temp_dir().'/main-project-'.getmypid().'-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($path.'/lang/en');
        File::put($path.'/GLOSSARY.md', "# Stockist\n");
        self::$made[] = $path;

        $project = new self($path);
        foreach (self::MENUS as $key => $label) {
            $project->menu($key, $label);
        }

        return $project->teamRoles(self::TEAM_ROLES)->adr(1, 'flexible-warehouse-location-spatial-hierarchy');
    }

    /** A real checkout of the main project, such as the picture tool's tests make, used as it is. */
    public static function checkout(string $path): self
    {
        return new self($path);
    }

    public static function removeAll(): void
    {
        foreach (self::$made as $path) {
            File::deleteDirectory($path);
        }
        self::$made = [];
    }

    /** Sets a translation (file.key.path) in lang/en, as a menu's label is defined. */
    public function menu(string $translationKey, string $label): self
    {
        [$file, $key] = explode('.', $translationKey, 2);
        Arr::set($this->lang[$file], $key, $label);

        return $this->write($file);
    }

    public function withoutMenu(string $translationKey): self
    {
        [$file, $key] = explode('.', $translationKey, 2);
        Arr::forget($this->lang[$file], $key);

        return $this->write($file);
    }

    /**
     * Writes First Mile Manager's role enums (app/Domain/Access): a case whose name starts
     * "Client" is a Client Role, the rest Staff Roles.
     *
     * @param  array<string, string>  $labels  case name => label
     */
    public function teamRoles(array $labels): self
    {
        $staff = array_filter($labels, fn (string $case): bool => ! str_starts_with($case, 'Client'), ARRAY_FILTER_USE_KEY);
        $client = array_diff_key($labels, $staff);
        $this->roleEnum('StaffRole', $staff);

        return $client === [] ? $this : $this->roleEnum('ClientRole', $client);
    }

    /** @param  array<string, string>  $labels  case name => label */
    private function roleEnum(string $enum, array $labels): self
    {
        $cases = implode("\n", array_map(fn (string $case): string => "    case {$case} = '".Str::snake($case)."';", array_keys($labels)));
        $matches = implode("\n", array_map(fn (string $case, string $label): string => "            self::{$case} => '{$label}',", array_keys($labels), $labels));

        return $this->file("app/Domain/Access/{$enum}.php", "<?php\n\nnamespace App\\Domain\\Access;\n\nenum {$enum}: string\n{\n{$cases}\n\n    public function label(): string\n    {\n        return match (\$this) {\n{$matches}\n        };\n    }\n}\n");
    }

    /**
     * Writes a plan at its repository path. With $helpContent it ends with a "## Help content"
     * section holding it, whose first line is the file's line 7; with null it has none.
     */
    public function plan(string $path, ?string $helpContent, string $before = "# A plan\n\nWhat it builds.\n"): self
    {
        return $this->file($path, $helpContent === null ? $before : "{$before}\n## Help content\n\n{$helpContent}\n");
    }

    /** Writes docs/adr/<nnnn>-<slug>.md, as the main project numbers its ADRs. */
    public function adr(int $number, string $slug = 'a-decision'): self
    {
        return $this->file(sprintf('docs/adr/%04d-%s.md', $number, $slug), "# {$number}. A decision\n");
    }

    /**
     * An issue in gpl-ajackson/firstmilemanager, as GitHub's REST API gives it: a spec when its title
     * starts "Spec:" or its body has a Help content section. $closed means closed as completed
     * (built); $pullRequest makes it a pull request, which GitHub also serves as an issue.
     */
    public function issue(int $number, string $title, string $body = '', bool $closed = false, bool $pullRequest = false, ?string $editedAt = null): self
    {
        if ($editedAt !== null) {
            $this->edits[$number] = $editedAt;
        }
        $this->issues[$number] = [
            'number' => $number,
            'title' => $title,
            'body' => $body,
            'state' => $closed ? 'closed' : 'open',
            'state_reason' => $closed ? 'completed' : null,
            ...($pullRequest ? ['pull_request' => ['url' => "https://api.github.com/repos/gpl-ajackson/firstmilemanager/pulls/{$number}"]] : []),
        ];

        return $this;
    }

    /**
     * When each issue's description was last edited (GitHub's lastEditedAt), for the issues
     * edited after they were opened; an issue never edited has none.
     *
     * @return array<int, string>
     */
    public function edits(): array
    {
        return $this->edits;
    }

    /**
     * A pull request merged into the main project's main at $mergedAt (ISO 8601, or anything
     * Carbon reads), with its description as written: a Help Proposal link, "No help changes:
     * <reason>", or neither.
     */
    public function mergedPullRequest(int $number, string $title, string $body, string $mergedAt, string $author = 'gpl-ajackson'): self
    {
        $this->mergedPullRequests[] = ['number' => $number, 'title' => $title, 'body' => $body, 'merged_at' => $mergedAt, 'author' => $author];

        return $this;
    }

    /** @return list<array{number: int, title: string, body: string, merged_at: string, author: string}> */
    public function mergedPullRequests(): array
    {
        return $this->mergedPullRequests;
    }

    /**
     * Commits everything in the folder as it is now, on $on (UK time unless it says otherwise),
     * as a merge to the main project's main would: the folder becomes a git checkout of main on
     * the first commit. The Help Drift Report reads this history for sources changed since an
     * Article's Checked On.
     */
    public function commit(string $message, string $on): self
    {
        $when = CarbonImmutable::parse($on, 'Europe/London')->toIso8601String();
        $git = fn (array $command) => Process::path($this->path)
            ->env(['GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com', 'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com', 'GIT_AUTHOR_DATE' => $when, 'GIT_COMMITTER_DATE' => $when])
            ->run(['git', ...$command])->throw();
        if (! is_dir($this->path.'/.git')) {
            $git(['init', '--quiet', '--initial-branch=main']);
        }
        $git(['add', '--all']);
        $git(['commit', '--quiet', '--allow-empty', '--message', $message]);

        return $this;
    }

    /** The last commit's id (see commit()). */
    public function head(): string
    {
        return trim(Process::path($this->path)->run(['git', 'rev-parse', 'HEAD'])->throw()->output());
    }

    /** Whether the folder is a git checkout yet (see commit()). */
    public function isCommitted(): bool
    {
        return is_dir($this->path.'/.git');
    }

    /** @return array<int, array<string, mixed>> The issues, by number, as GitHub's API gives them. */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * Writes GLOSSARY.md as the main project writes it: each term in bold with its definition
     * under it and an "_Avoid_:" line of words not to use for it.
     *
     *     mainProject()->glossary(['Location' => ['A place stock is kept.', ['Bin', 'slot']]]);
     *
     * @param  array<string, string|array{0: string, 1?: list<string>}>  $terms  term => definition, or [definition, avoid words]
     */
    public function glossary(array $terms): self
    {
        $markdown = "# Stockist Warehouse Management\n\nDomain terminology.\n\n## Language\n\n### Terms\n";
        foreach ($terms as $term => $entry) {
            [$definition, $avoid] = is_string($entry) ? [$entry, []] : [$entry[0], $entry[1] ?? []];
            $markdown .= "\n**{$term}**:\n{$definition}\n".($avoid === [] ? '' : '_Avoid_: '.implode(', ', $avoid)."\n");
        }

        return $this->file('GLOSSARY.md', $markdown);
    }

    /** Writes any file into the folder, exactly as given. */
    public function file(string $relativePath, string $contents): self
    {
        File::ensureDirectoryExists(dirname($this->path.'/'.$relativePath));
        File::put($this->path.'/'.$relativePath, $contents);

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }

    private function write(string $file): self
    {
        return $this->file("lang/en/{$file}.php", "<?php\n\nreturn ".self::export($this->lang[$file] ?? []).";\n");
    }

    /** Short array syntax, as Stockist's language files are written. */
    private static function export(mixed $value, int $depth = 0): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }
        $indent = str_repeat('    ', $depth + 1);
        $lines = array_map(fn (mixed $item, int|string $key): string => $indent.var_export($key, true).' => '.self::export($item, $depth + 1).',', $value, array_keys($value));

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }
}

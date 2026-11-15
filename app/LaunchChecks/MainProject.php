<?php

namespace App\LaunchChecks;

use App\LaunchChecks\Glossary\MainGlossary;
use Illuminate\Support\Arr;

/**
 * A checkout of the main project (gpl-ajackson/firstmilemanager) at the ref under test: `main`, or the
 * paired main-project pull request. The checks read it as text and never run it. Tests use a
 * folder shaped like it (tests/Support/FixtureMainProject.php).
 */
final class MainProject
{
    /** @var array<string, array<mixed>|null> */
    private array $languageFiles = [];

    /** GLOSSARY.md once read (null when there is none); false until then. */
    private MainGlossary|false|null $glossary = false;

    public function __construct(public readonly string $path) {}

    /**
     * An English translation by its key (`<file>.<key path>`, as `__()` takes it), such as a
     * menu's label: `client-accounts/client-account.navigationLabel` reads
     * lang/en/client-accounts/client-account.php. A key with no dot is a plain-text key, as First
     * Mile Manager's menus mostly use (`__('People')`): lang/en.json's translation, or the key
     * itself when the app's code passes it to `__()`. Null when missing.
     */
    public function translation(string $key): ?string
    {
        if (! str_contains($key, '.')) {
            return $this->plainTextTranslation($key);
        }
        [$file, $path] = explode('.', $key, 2);
        $value = Arr::get($this->languageFile($file) ?? [], $path);

        return is_string($value) ? $value : null;
    }

    /** The language file a translation key reads, as the main project's repository names it. */
    public static function languageFileOf(string $key): string
    {
        return 'lang/en/'.explode('.', $key, 2)[0].'.php';
    }

    /**
     * Where First Mile Manager names its Team Roles: the Staff Roles' enum, and the Client Roles'
     * once they are built (slice 1), each naming its roles in label().
     */
    public const TEAM_ROLE_FILES = ['app/Domain/Access/StaffRole.php', 'app/Domain/Access/ClientRole.php'];

    /**
     * The Team Roles First Mile Manager has as built, as its screens name them: the labels its
     * role enums' label() gives (`self::Admin => 'Admin'`).
     *
     * @return list<string>
     */
    public function teamRoles(): array
    {
        $roles = [];
        foreach (self::TEAM_ROLE_FILES as $file) {
            $code = $this->read($file);
            if ($code === null || preg_match('/function label\(\)[^{]*\{(.*?)\n    \}/s', $code, $body) !== 1) {
                continue;
            }
            preg_match_all("/self::\w+\s*=>\s*(?:__\()?'([^']+)'/", $body[1], $labels);
            array_push($roles, ...$labels[1]);
        }

        return array_values(array_unique($roles));
    }

    /**
     * A file's text by its path in the repository, such as a plan (`.junie/plans/...md`); null
     * when there is no such file, or the path leads outside the checkout.
     */
    public function read(string $path): ?string
    {
        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return null;
        }
        $file = $this->path.'/'.$path;

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** An ADR's path by its number (ADR-0019 is docs/adr/0019-<title>.md); null when there is none. */
    public function adr(int $number): ?string
    {
        $files = glob(sprintf('%s/docs/adr/%04d-*.md', $this->path, $number)) ?: [];

        return $files === [] ? null : substr($files[0], strlen($this->path) + 1);
    }

    /** Its GLOSSARY.md, the domain language; null when the checkout has none. */
    public function glossary(): ?MainGlossary
    {
        if ($this->glossary === false) {
            $this->glossary = MainGlossary::read($this->path.'/'.MainGlossary::FILE);
        }

        return $this->glossary;
    }

    /**
     * Every piece of English text Stockist's screens can show, by language file (lang/en/*.php,
     * as the main project's repository names it): labels, headings and messages.
     *
     * @return array<string, list<string>>
     */
    public function screenText(): array
    {
        $text = [];
        foreach (glob($this->path.'/lang/en/*.php') ?: [] as $path) {
            $file = basename($path, '.php');
            $strings = [];
            $array = $this->languageFile($file) ?? [];
            array_walk_recursive($array, function (mixed $value) use (&$strings): void {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });
            $text['lang/en/'.$file.'.php'] = $strings;
        }

        return $text;
    }

    private function plainTextTranslation(string $key): ?string
    {
        $json = $this->read('lang/en.json');
        $strings = $json === null ? null : json_decode($json, true);
        if (is_array($strings) && is_string($strings[$key] ?? null)) {
            return $strings[$key];
        }
        $quoted = preg_quote(var_export($key, true), '/');
        foreach ($this->phpFiles('app') as $file) {
            if (preg_match('/__\(\s*'.$quoted.'\s*\)/', (string) file_get_contents($file)) === 1) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<string> every PHP file under a folder of the checkout */
    private function phpFiles(string $folder): array
    {
        $root = $this->path.'/'.$folder;
        if (! is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<mixed>|null */
    private function languageFile(string $file): ?array
    {
        if (preg_match('/\A[A-Za-z0-9_\/-]+\z/', $file) !== 1 || str_contains($file, '..')) {
            return null;
        }

        return $this->languageFiles[$file] ??= PhpArrayFile::read($this->path.'/lang/en/'.$file.'.php');
    }
}

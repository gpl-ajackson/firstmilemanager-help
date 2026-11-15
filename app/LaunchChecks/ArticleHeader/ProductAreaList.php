<?php

namespace App\LaunchChecks\ArticleHeader;

use App\LaunchChecks\Findings;
use App\LaunchChecks\MainProject;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Product Area list, `product-areas.yaml` in the content folder: the Stockist app's main
 * menus, name for name, each with the main project's translation key for its menu label.
 *
 *     - name: Warehouses
 *       menu: warehouses.cluster.title
 *
 * Check 1 fails an Article whose Product Area isn't listed, and a listed area whose menu label
 * the main project no longer has, or names differently.
 */
final readonly class ProductAreaList
{
    public const FILE = 'product-areas.yaml';

    /**
     * @param  array<string, array{menu: string, line: int}>  $areas  name => its menu key and line
     */
    private function __construct(public bool $exists, public array $areas) {}

    public static function read(string $contentPath, Findings $findings): self
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            return new self(false, []);
        }

        $contents = (string) file_get_contents($path);
        try {
            $list = Yaml::parse($contents);
        } catch (ParseException $e) {
            $findings->fail('product-areas.format', self::FILE, $e->getParsedLine() > 0 ? $e->getParsedLine() : 1, 'is not valid YAML: '.$e->getMessage());

            return new self(true, []);
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $areas = [];
        foreach (is_array($list) ? $list : [null] as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            $menu = is_array($entry) ? ($entry['menu'] ?? null) : null;
            if (! is_string($name) || ! is_string($menu) || $name === '' || $menu === '') {
                $findings->fail('product-areas.format', self::FILE, 1, 'each Product Area needs "name" (as the app\'s menu shows it) and "menu" (the main project\'s translation key for that label)');

                continue;
            }
            $areas[$name] = ['menu' => $menu, 'line' => self::lineOf($lines, $name)];
        }

        return new self(true, $areas);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->areas);
    }

    public function has(string $name): bool
    {
        return isset($this->areas[$name]);
    }

    /** Fails each listed Product Area the main project's menus don't have, name for name. */
    public function checkAgainst(MainProject $mainProject, Findings $findings): void
    {
        foreach ($this->areas as $name => ['menu' => $menu, 'line' => $line]) {
            $label = $mainProject->translation($menu);
            if ($label === null) {
                $findings->fail('product-areas.menu', self::FILE, $line, "\"{$name}\": the main project has no menu label {$menu} (".MainProject::languageFileOf($menu).')');
            } elseif ($label !== $name) {
                $findings->fail('product-areas.menu', self::FILE, $line, "\"{$name}\" must be named exactly as the app's menu, which is now \"{$label}\" ({$menu})");
            }
        }
    }

    /** @param  list<string>  $lines */
    private static function lineOf(array $lines, string $name): int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*-?\s*name\s*:\s*([\'"]?)'.preg_quote($name, '/').'\1\s*(#.*)?$/', $line) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }
}

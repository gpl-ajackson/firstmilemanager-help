<?php

namespace App\Content;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One Product Area as content/product-areas.yaml lists it: the Stockist app's main menus, name
 * for name and in the menus' order, which is the order the site shows Product Areas in.
 *
 *     - name: Stock Containers          exactly as the app's menu shows it
 *       menu: stock-containers.cluster.title   the main project's label key (Launch Check 1)
 *       former_names: [Containers]      optional: its names before the menu was renamed
 *
 * A Product Area's address follows its name (addressOf(): "Stock Containers" is
 * /areas/stock-containers), so a renamed area keeps its old names here and each old address
 * redirects for good (ADR-0001). The list is optional to the rebuild: an area no file lists
 * still appears once it has a Published Article, after the listed ones, by name. That an
 * Article's area is listed, and each listed name matches the app's menu, is Launch Check 1's.
 */
final readonly class ProductAreaSource
{
    public const FILE = 'product-areas.yaml';

    public const FIELDS = ['name', 'menu', 'former_names'];

    private function __construct(
        public string $name,
        /** @var list<string> */
        public array $formerNames,
    ) {}

    /** A Product Area's listing: /areas/ and its name in lower case with hyphens. */
    public static function addressOf(string $name): string
    {
        return '/areas/'.Str::slug($name);
    }

    public function address(): string
    {
        return self::addressOf($this->name);
    }

    /**
     * Every Product Area the content folder at $contentPath lists, in the menus' order (none
     * when there is no list), or adds what is wrong to $problems.
     *
     * @param  list<ContentProblem>  $problems
     * @return list<self>
     */
    public static function readAll(string $contentPath, array &$problems): array
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            return [];
        }

        try {
            $list = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException $e) {
            $problems[] = new ContentProblem(self::FILE, 'file', 'is not valid YAML: '.$e->getMessage());

            return [];
        }
        if (! is_array($list) || ! array_is_list($list)) {
            $problems[] = new ContentProblem(self::FILE, 'file', 'must be a list of Product Areas, one "- name: ..." each');

            return [];
        }

        $areas = [];
        foreach ($list as $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            if (! is_array($entry) || $name === '') {
                $problems[] = new ContentProblem(self::FILE, 'name', 'each Product Area needs its name, as the app\'s menu shows it');

                continue;
            }
            foreach (array_diff(array_keys($entry), self::FIELDS) as $unknown) {
                $problems[] = new ContentProblem(self::FILE, (string) $unknown, "\"{$name}\": is not a Product Area field (they are ".implode(', ', self::FIELDS).')');
            }
            $areas[] = new self($name, self::formerNames($entry['former_names'] ?? [], $name, $problems));
        }

        return $areas;
    }

    /**
     * A listed area's former names: its old names, each a piece of text.
     *
     * @param  list<ContentProblem>  $problems
     * @return list<string>
     */
    private static function formerNames(mixed $value, string $name, array &$problems): array
    {
        $names = [];
        foreach (is_array($value) && array_is_list($value) ? $value : [null] as $old) {
            if (! is_string($old) || trim($old) === '') {
                $problems[] = new ContentProblem(self::FILE, 'former_names', "\"{$name}\": must be a list of the area's old names");

                return [];
            }
            $names[] = trim($old);
        }

        return $names;
    }
}

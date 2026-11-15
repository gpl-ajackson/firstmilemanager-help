<?php

namespace App\Content;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The features help is written about, as content/features.yaml lists them, and whether each is
 * on the demo yet (ADR-0006, "Shown once on the demo"):
 *
 *     Staff sign-in and roles:      the feature's name, as Articles name it in their `feature`
 *       spec: fmm#57                the main-project spec or issue that builds it
 *       on_demo: false              true once an FMM Release has put it on the demo
 *
 * A Published Article naming a feature that isn't on the demo waits, hidden as a Draft is, until
 * the FMM Release that puts it there sets on_demo: true. An Article that names no feature (the
 * help centre's own pages, such as getting started) shows once Published.
 */
final readonly class FeatureList
{
    public const FILE = 'features.yaml';

    public const FIELDS = ['spec', 'on_demo'];

    /** @param  array<string, bool>  $onDemo  each feature's name => whether it is on the demo */
    private function __construct(public array $onDemo) {}

    public static function none(): self
    {
        return new self([]);
    }

    public function has(string $feature): bool
    {
        return array_key_exists($feature, $this->onDemo);
    }

    public function isOnDemo(string $feature): bool
    {
        return $this->onDemo[$feature] ?? false;
    }

    /**
     * The list in the content folder at $contentPath (empty when there is no file), or adds what
     * is wrong to $problems.
     *
     * @param  list<ContentProblem>  $problems
     */
    public static function read(string $contentPath, array &$problems): self
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            return self::none();
        }

        try {
            $list = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException $e) {
            $problems[] = new ContentProblem(self::FILE, 'file', 'is not valid YAML: '.$e->getMessage());

            return self::none();
        }
        if ($list === null) {
            return self::none();
        }
        if (! is_array($list) || array_is_list($list)) {
            $problems[] = new ContentProblem(self::FILE, 'file', 'must list each feature by name, with its spec and on_demo: true or false');

            return self::none();
        }

        $onDemo = [];
        foreach ($list as $name => $fields) {
            $name = trim((string) $name);
            if (! is_array($fields)) {
                $problems[] = new ContentProblem(self::FILE, $name, 'needs its spec and on_demo: true or false');

                continue;
            }
            foreach (array_diff(array_keys($fields), self::FIELDS) as $unknown) {
                $problems[] = new ContentProblem(self::FILE, (string) $unknown, "\"{$name}\": is not a feature field (they are ".implode(', ', self::FIELDS).')');
            }
            if (! is_bool($fields['on_demo'] ?? null)) {
                $problems[] = new ContentProblem(self::FILE, 'on_demo', "\"{$name}\": must be true or false (true once an FMM Release has put it on the demo)");

                continue;
            }
            $onDemo[$name] = $fields['on_demo'];
        }

        return new self($onDemo);
    }
}

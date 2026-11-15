<?php

namespace App\Content;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The help centre's facts, content/facts.yaml: Stockist's name, one-line description, stage
 * and the marketing site's address, copied word for word from the marketing site's
 * src/data/site.ts (the source), so the two sites say the same thing (plan, "The help centre
 * and the marketing site"). Launch Check 9 compares them with site.ts.
 *
 *     name: Stockist WMS
 *     description: Stockist WMS is warehouse management software for ...
 *     stage: Early access
 *     url: https://firstmilemanager.co.uk
 */
final readonly class FactsSource
{
    public const FILE = 'facts.yaml';

    /** The facts, named as site.ts names them. */
    public const FIELDS = ['name', 'description', 'stage', 'url'];

    private function __construct(
        public string $name,
        public string $description,
        public string $stage,
        public string $url,
    ) {}

    /**
     * Reads the facts from the content folder at $contentPath, or adds what is wrong to $problems.
     *
     * @param  list<ContentProblem>  $problems
     */
    public static function read(string $contentPath, array &$problems): ?self
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            $problems[] = new ContentProblem(self::FILE, 'file', 'the content folder has no facts file (First Mile Manager\'s name, description, stage and url, copied from the marketing site\'s site.ts)');

            return null;
        }

        try {
            $facts = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException $e) {
            $problems[] = new ContentProblem(self::FILE, 'file', 'is not valid YAML: '.$e->getMessage());

            return null;
        }
        if (! is_array($facts) || array_is_list($facts)) {
            $problems[] = new ContentProblem(self::FILE, 'file', 'must be a list of "fact: value" lines');

            return null;
        }

        $found = count($problems);
        foreach (array_diff(array_keys($facts), self::FIELDS) as $unknown) {
            $problems[] = new ContentProblem(self::FILE, (string) $unknown, 'is not a fact the help centre knows (it knows '.implode(', ', self::FIELDS).')');
        }
        $text = function (string $field) use ($facts, &$problems): ?string {
            $value = $facts[$field] ?? null;
            if ($value === null || $value === '') {
                $problems[] = new ContentProblem(self::FILE, $field, 'is missing');
            } elseif (! is_string($value) || str_contains($value, "\n")) {
                $problems[] = new ContentProblem(self::FILE, $field, 'must be text on one line');
            } else {
                return trim($value);
            }

            return null;
        };
        $name = $text('name');
        $description = $text('description');
        $stage = $text('stage');
        $url = $text('url');
        if ($url !== null && preg_match('#\Ahttps://[a-z0-9.-]+\z#', $url) !== 1) {
            $problems[] = new ContentProblem(self::FILE, 'url', 'must be the marketing site\'s https:// address, with nothing after it');
        }

        if (count($problems) > $found || $name === null || $description === null || $stage === null || $url === null) {
            return null;
        }

        return new self($name, $description, $stage, $url);
    }

    /** @return array{name: string, description: string, stage: string, url: string} */
    public function row(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'stage' => $this->stage, 'url' => $this->url];
    }
}

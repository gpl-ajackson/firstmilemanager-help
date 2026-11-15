<?php

namespace App\Pictures;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A Picture Recipe: the written steps that make one Picture, in the screen's own words (spec
 * #30 "Pictures", ADR-0005). It lives beside its Picture as pictures/<Picture Name>.yaml:
 *
 *     signs_in_as: Sarah Whitfield
 *     starts_on: Warehouses › Locations
 *     device: desk
 *     steps:
 *       - enter: { Search: QA-0 }
 *     keep: [Locations, Per page]
 *     outline: New Location
 *     alt: The Locations list filtered to QA-0, with New Location at the top right.
 *     articles: [add-a-location]
 *
 * read() checks every field and names each problem by file and field; the tool reads every
 * Recipe before it starts Stockist, so mistakes are found in seconds.
 */
final readonly class Recipe
{
    public const array FIELDS = ['signs_in_as', 'starts_on', 'device', 'steps', 'keep', 'outline', 'alt', 'articles'];

    /** Alt text is one sentence of about 125 characters at most (plan, "Pictures"). */
    public const int ALT_MAX = 125;

    private const string NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /**
     * @param  list<string>  $startsOn  the menu route, one menu item each
     * @param  list<RecipeStep>  $steps
     * @param  list<string>  $keep  ['screen'], or the words heading each part to keep
     * @param  list<string>  $articles  the Article Names of the Articles that use the Picture
     */
    public function __construct(
        public string $name,
        public string $file,
        public string $signsInAs,
        public array $startsOn,
        public Device $device,
        public array $steps,
        public array $keep,
        public ?string $outline,
        public string $alt,
        public array $articles,
    ) {}

    /** Whether the Picture is of a client's screen (Client-tier Pictures start `client-`). */
    public function isClientTier(): bool
    {
        return str_starts_with($this->name, 'client-');
    }

    /**
     * Reads and checks the Recipe at $path; $file is how reports name it.
     *
     * @throws InvalidRecipe
     */
    public static function read(string $path, ?string $file = null): self
    {
        $file ??= basename($path);
        $name = basename($path, '.yaml');
        $problems = [];
        $problem = function (string $field, string $message) use (&$problems, $file): void {
            $problems[] = new RecipeProblem($file, $field, $message);
        };

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $problem('Picture Name', 'use lower case letters, digits and single hyphens, named after the screen ('.$name.')');
        }

        try {
            $fields = Yaml::parse((string) file_get_contents($path));
        } catch (ParseException) {
            $fields = null;
        }
        if (! is_array($fields) || array_is_list($fields)) {
            throw new InvalidRecipe([new RecipeProblem($file, '', 'is not a YAML mapping of Recipe fields')]);
        }

        foreach (array_keys($fields) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                $problem((string) $field, 'is not a Recipe field (fields: '.implode(', ', self::FIELDS).')');
            }
        }

        $signsInAs = self::text($fields, 'signs_in_as', $problem);
        $startsOn = self::text($fields, 'starts_on', $problem);

        $device = null;
        if (! array_key_exists('device', $fields)) {
            $problem('device', 'is missing');
        } else {
            $device = is_string($fields['device']) ? Device::tryFrom($fields['device']) : null;
            if ($device === null) {
                $problem('device', 'must be desk or handheld');
            }
        }

        $steps = self::steps($fields['steps'] ?? [], $problem);
        $keep = self::keep($fields, $problem);

        $outline = $fields['outline'] ?? null;
        if ($outline !== null && (! is_scalar($outline) || trim((string) $outline) === '')) {
            $problem('outline', 'must be the words of the one thing to outline');
            $outline = null;
        }

        $alt = self::text($fields, 'alt', $problem);
        if ($alt !== null && mb_strlen($alt) > self::ALT_MAX) {
            $problem('alt', 'is '.mb_strlen($alt).' characters; keep it to '.self::ALT_MAX);
        }
        if ($alt !== null && preg_match('/\A(screenshot|image|picture)\b/i', $alt) === 1) {
            $problem('alt', 'must not start "Screenshot", "Image" or "Picture": say what the reader needs from it');
        }

        $articles = $fields['articles'] ?? null;
        if (! is_array($articles) || $articles === [] || ! array_is_list($articles)) {
            $problem('articles', 'list the Articles that use this Picture, by Article Name');
            $articles = [];
        }
        foreach ($articles as $article) {
            if (! is_string($article) || preg_match(self::NAME_PATTERN, $article) !== 1) {
                $problem('articles', '"'.(is_scalar($article) ? $article : gettype($article)).'" is not an Article Name');
            }
        }

        if ($problems !== [] || $signsInAs === null || $startsOn === null || $device === null || $alt === null) {
            throw new InvalidRecipe($problems === [] ? [new RecipeProblem($file, '', 'is incomplete')] : $problems);
        }

        /** @var list<string> $articles */
        return new self(
            name: $name,
            file: $file,
            signsInAs: $signsInAs,
            startsOn: self::route($startsOn),
            device: $device,
            steps: $steps,
            keep: $keep,
            outline: $outline === null ? null : trim((string) $outline),
            alt: $alt,
            articles: $articles,
        );
    }

    /**
     * A menu route as its menu items: "Warehouses › Locations" (or "Warehouses > Locations").
     *
     * @return list<string>
     */
    public static function route(string $route): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\s*[›>]\s*/u', $route) ?: []), fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<mixed>  $fields
     * @param  callable(string, string): void  $problem
     */
    private static function text(array $fields, string $field, callable $problem): ?string
    {
        if (! array_key_exists($field, $fields)) {
            $problem($field, 'is missing');

            return null;
        }
        $value = $fields[$field];
        if (! is_scalar($value) || trim((string) $value) === '') {
            $problem($field, 'must be text');

            return null;
        }

        return trim((string) $value);
    }

    /**
     * @param  array<mixed>  $fields
     * @param  callable(string, string): void  $problem
     * @return list<string>
     */
    private static function keep(array $fields, callable $problem): array
    {
        $keep = $fields['keep'] ?? null;
        $keep = is_string($keep) ? [$keep] : $keep;
        if (! is_array($keep) || $keep === [] || ! array_is_list($keep)) {
            $problem('keep', 'must be "screen" or the words of what to keep (a heading, or a list of them)');

            return [];
        }
        $words = [];
        foreach ($keep as $item) {
            if (! is_scalar($item) || trim((string) $item) === '') {
                $problem('keep', 'must be "screen" or the words of what to keep (a heading, or a list of them)');

                return [];
            }
            $words[] = trim((string) $item);
        }
        if (in_array('screen', $words, true) && count($words) > 1) {
            $problem('keep', '"screen" keeps the whole screen, so nothing else goes with it');
        }

        return $words;
    }

    /**
     * @param  callable(string, string): void  $problem
     * @return list<RecipeStep>
     */
    private static function steps(mixed $steps, callable $problem): array
    {
        if (! is_array($steps) || ! array_is_list($steps)) {
            $problem('steps', 'must be a list of steps');

            return [];
        }

        $read = [];
        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $kinds = is_array($step) ? array_values(array_intersect(array_keys($step), RecipeStep::KINDS)) : [];
            $others = is_array($step) ? array_diff(array_keys($step), [...RecipeStep::KINDS, 'in']) : [];
            if (! is_array($step) || $kinds === [] || $others !== []) {
                $problem('steps', "step {$number} must be one of ".implode(', ', RecipeStep::KINDS).' (and may say "in" to name the part of the screen)');

                continue;
            }
            if (count($kinds) > 1) {
                $problem('steps', "step {$number} must do one thing; it says ".implode(' and ', $kinds));

                continue;
            }

            /** @var 'go_to'|'select'|'enter'|'scan'|'wait_for' $kind */
            $kind = $kinds[0];
            $value = $step[$kind];
            $in = isset($step['in']) && is_scalar($step['in']) ? trim((string) $step['in']) : null;
            $suffix = $in === null ? '' : " (in {$in})";

            if (is_array($value) && in_array($kind, ['select', 'enter'], true) && count($value) === 1 && ! array_is_list($value) && is_scalar(reset($value))) {
                $field = trim((string) array_key_first($value));
                $words = trim((string) reset($value));
                $read[] = new RecipeStep($kind, $words, $field, $in, "{$kind}: {$field}: {$words}{$suffix}");

                continue;
            }
            if ($kind === 'enter') {
                $problem('steps', "step {$number} enter must name one field and what to type, as \"enter: {Name: Bay 1}\"");

                continue;
            }
            if (! is_scalar($value) || trim((string) $value) === '') {
                $problem('steps', "step {$number} {$kind} must say what the screen shows".($kind === 'select' ? ', or name one field and its choice, as "select: {Warehouse: Colwick Distribution Centre}"' : ''));

                continue;
            }
            $words = trim((string) $value);
            $read[] = new RecipeStep($kind, $words, null, $in, "{$kind}: {$words}{$suffix}");
        }

        return $read;
    }
}

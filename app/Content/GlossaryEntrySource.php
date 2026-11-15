<?php

namespace App\Content;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One Glossary Entry file, read and checked: the help centre's plain-language explanation of
 * one GLOSSARY.md term for one Audience Tier. Staff entries live in content/glossary/, Client
 * Glossary entries in content/client-glossary/, one file per term:
 *
 *     ---
 *     term: Location                       exactly as GLOSSARY.md names it
 *     audience_tier: Staff             Staff in glossary/, Client in client-glossary/
 *     other_names: [bin, slot]             optional: words readers really use for it
 *     where_you_see_it: On the Warehouses menu, and on every label you scan.
 *     status_values:                       optional: for a status, each value and what it means
 *       - value: Active
 *         meaning: Deliveries can be booked in.
 *     former_names: [Storage Location]     optional: its names before GLOSSARY.md renamed it
 *     checked_on: 2026-10-24               the date it was last checked against GLOSSARY.md
 *     ---
 *
 *     One or two plain sentences on what the term is.
 *
 * The file name is the term's name in its address (addressNameOf(): "Stock Container" is
 * stock-container), so the address follows the term's current name; a renamed term's file is
 * renamed and its old name kept in former_names, whose addresses redirect for good.
 * "The Articles that use it" is never written here: the rebuild works it out from the links.
 */
final readonly class GlossaryEntrySource
{
    use HeaderData;

    /** The header data fields an entry may carry, in the order writers write them. */
    public const FIELDS = ['term', 'audience_tier', 'other_names', 'where_you_see_it', 'status_values', 'former_names', 'checked_on'];

    public const OPTIONAL_FIELDS = ['other_names', 'status_values', 'former_names'];

    /** Each tier's folder in the content folder, which is also its address's first part. */
    public const FOLDERS = ['glossary' => AudienceTier::Staff, 'client-glossary' => AudienceTier::Client];

    /** A status has at least two values, or it isn't a status. */
    public const STATUS_VALUES_MIN = 2;

    private const NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private function __construct(
        /** The term's name in its address: the file name without ".md". */
        public string $name,
        public string $file,
        public string $term,
        public AudienceTier $audienceTier,
        /** @var list<string> */
        public array $otherNames,
        public string $whereYouSeeIt,
        /** @var list<array{value: string, meaning: string}> */
        public array $statusValues,
        /** @var list<string> */
        public array $formerNames,
        public CarbonImmutable $checkedOn,
        public string $body,
        /** The file's line on which the body starts, so a problem in the body names its line. */
        public int $bodyLine,
    ) {}

    /** The entry's address: /glossary/<name> for Staff, /client-glossary/<name> for clients. */
    public function address(): string
    {
        return self::addressOf($this->name, $this->audienceTier);
    }

    public static function addressOf(string $name, AudienceTier $tier): string
    {
        return '/'.array_search($tier, self::FOLDERS, true).'/'.$name;
    }

    /** A term's name in its address: lower case, words joined by hyphens ("Stock Container" is stock-container). */
    public static function addressNameOf(string $term): string
    {
        return Str::slug($term);
    }

    /**
     * @param  string  $path  The file to read.
     * @param  string  $file  Its path as problems name it (relative to the content folder).
     * @param  AudienceTier  $folderTier  The tier whose folder it is in.
     * @param  list<ContentProblem>  $problems
     */
    public static function read(string $path, string $file, AudienceTier $folderTier, array &$problems): ?self
    {
        $before = count($problems);
        $problem = function (string $field, string $message) use ($file, &$problems): void {
            $problems[] = new ContentProblem($file, $field, $message);
        };

        $read = self::frontMatter((string) file_get_contents($path), $problem);
        if ($read === null) {
            return null;
        }
        [$header, $yaml, $rest] = $read;

        foreach (array_keys($header) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                $problem((string) $field, 'is not a Glossary Entry header data field (known fields: '.implode(', ', self::FIELDS).')');
            }
        }
        foreach (array_diff(self::FIELDS, self::OPTIONAL_FIELDS) as $field) {
            if (self::isMissing($header, $field)) {
                $problem($field, 'is missing');
            }
        }

        $name = basename($path, '.md');
        $term = self::text($header, 'term', null, $problem);
        if ($term !== null) {
            $addressName = self::addressNameOf($term);
            if (str_contains($addressName, 'stockist')) {
                $problem('term', "\"{$term}\" would give an address containing \"stockist\", which a reader at an FMM-only Operator mustn't see");
            } elseif ($name !== $addressName) {
                $problem('term', "the file for \"{$term}\" must be named {$addressName}.md, so its address follows the term's name");
            }
        }

        $tier = self::choice($header, 'audience_tier', AudienceTier::class, $problem);
        if ($tier !== null && $tier !== $folderTier) {
            $folder = array_search($tier, self::FOLDERS, true);
            $problem('audience_tier', "\"{$tier->value}\" entries belong in the {$folder} folder");
        }

        $otherNames = self::names($header, 'other_names', null, '', $problem);
        foreach ($otherNames as $other) {
            if ($term !== null && mb_strtolower($other) === mb_strtolower($term)) {
                $problem('other_names', "\"{$other}\" is the term itself");
            }
        }

        $whereYouSeeIt = self::text($header, 'where_you_see_it', null, $problem);
        $statusValues = self::statusValues($header, $problem);
        $formerNames = self::formerNames($header, $term, $problem);
        $checkedOn = self::date($header, 'checked_on', $yaml, $problem);

        $body = trim($rest);
        $headerLines = preg_split('/\R/', $yaml) ?: [];
        $bodyLine = count($headerLines) + 3 + substr_count(substr($rest, 0, strspn($rest, " \t\r\n")), "\n");
        if ($body === '') {
            $problem('body', 'the entry has no text after its header data: say what the term is in one or two plain sentences');
        }

        if (count($problems) > $before || $term === null || $tier === null || $whereYouSeeIt === null || $checkedOn === null) {
            return null;
        }

        return new self($name, $file, $term, $tier, $otherNames, $whereYouSeeIt, $statusValues, $formerNames, $checkedOn, $body, $bodyLine);
    }

    /**
     * A status's values, each with a line on what it means, in the writer's order.
     *
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     * @return list<array{value: string, meaning: string}>
     */
    private static function statusValues(array $header, callable $problem): array
    {
        if (self::isMissing($header, 'status_values')) {
            return [];
        }
        $items = $header['status_values'];
        $says = 'must be a list of the status\'s values, each "- value: <value>" with "meaning: <what it means>"';
        if (! is_array($items) || ! array_is_list($items)) {
            $problem('status_values', $says);

            return [];
        }

        $values = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['value', 'meaning']) !== []) {
                $problem('status_values', $says);

                continue;
            }
            $value = is_string($item['value'] ?? null) ? trim($item['value']) : '';
            $meaning = is_string($item['meaning'] ?? null) ? trim($item['meaning']) : '';
            if ($value === '' || $meaning === '') {
                $problem('status_values', $says);

                continue;
            }
            if (in_array($value, array_column($values, 'value'), true)) {
                $problem('status_values', "\"{$value}\" is listed twice");

                continue;
            }
            $values[] = ['value' => $value, 'meaning' => $meaning];
        }
        if ($values !== [] && count($items) < self::STATUS_VALUES_MIN) {
            $problem('status_values', 'a status has at least two values; leave status_values out of an entry that isn\'t a status');
        }

        return $values;
    }

    /**
     * The term's names before GLOSSARY.md renamed it, whose addresses redirect here for good.
     *
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     * @return list<string>
     */
    private static function formerNames(array $header, ?string $term, callable $problem): array
    {
        $names = [];
        foreach (self::names($header, 'former_names', null, '', $problem) as $former) {
            $addressName = self::addressNameOf($former);
            if (preg_match(self::NAME_PATTERN, $addressName) !== 1) {
                $problem('former_names', "\"{$former}\" must be a name in words");
            } elseif ($term !== null && $addressName === self::addressNameOf($term)) {
                $problem('former_names', "\"{$former}\" is the term's own name");
            } else {
                $names[] = $former;
            }
        }

        return $names;
    }
}

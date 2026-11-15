<?php

namespace App\LaunchChecks\Glossary;

use App\LaunchChecks\ArticleHeader\ProductAreaList;
use App\LaunchChecks\Findings;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Term Status list, `term-status.yaml` in the content folder: every GLOSSARY.md term by its
 * exact name, with its Term Status, its home Product Area, the Ahead mark where its entries
 * say less than GLOSSARY.md, and the fingerprint of the definition its entries were last
 * checked against (the Glossary Check reports the term when GLOSSARY.md's definition changes).
 *
 *     Location:
 *       status: Published              Published, Internal or Not built yet
 *       product_area: Warehouses       its home Product Area; a Published term's is listed in product-areas.yaml
 *       fingerprint: 6974c41e29f4      what the Glossary Check says to set
 *     Weight Override:
 *       status: Published
 *       product_area: Inbound
 *       ahead: true                    only on a Published term
 *       fingerprint: 0a1b2c3d4e5f
 *     Admin:
 *       status: Published
 *       product_area: People
 *       fingerprint: 1a2b3c4d5e6f
 *       stockist_fingerprint: 6f5e4d3c2b1a   a term Stockist's GLOSSARY.md has too: Stockist's definition, as last checked
 *
 * Each problem fails the Glossary Check as glossary.term-status-format, naming the term's line.
 */
final readonly class TermStatusList
{
    public const FILE = 'term-status.yaml';

    public const FIELDS = ['status', 'product_area', 'ahead', 'fingerprint', 'stockist_fingerprint'];

    /** @param  array<string, TermStatusEntry>  $entries  term => its Term Status, for the terms that read cleanly */
    private function __construct(
        public bool $exists,
        public array $entries,
        /** @var array<string, int> every term the list names, readable or not, with its line */
        public array $named,
    ) {}

    public static function read(string $contentPath, ?MainGlossary $glossary, ProductAreaList $areas, Findings $findings): self
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            return new self(false, [], []);
        }

        $contents = (string) file_get_contents($path);
        try {
            $list = Yaml::parse($contents);
        } catch (ParseException $e) {
            $findings->fail('glossary.term-status-format', self::FILE, max(1, $e->getParsedLine()), 'is not valid YAML: '.$e->getMessage());

            return new self(true, [], []);
        }
        if ($list === null) {
            return new self(true, [], []);
        }
        if (! is_array($list) || array_is_list($list)) {
            $findings->fail('glossary.term-status-format', self::FILE, 1, 'must list each term by its exact GLOSSARY.md name, as "<term>:" with its fields under it');

            return new self(true, [], []);
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $entries = [];
        $named = [];
        foreach ($list as $term => $fields) {
            $term = (string) $term;
            $line = self::lineOf($lines, $term);
            $named[$term] = $line;
            $fail = fn (string $message) => $findings->fail('glossary.term-status-format', self::FILE, $line, "\"{$term}\": {$message}");
            $entry = self::entry($term, $fields, $line, $glossary, $areas, $fail);
            if ($entry !== null) {
                $entries[$term] = $entry;
            }
        }

        return new self(true, $entries, $named);
    }

    /** @param  callable(string): void  $fail */
    private static function entry(string $term, mixed $fields, int $line, ?MainGlossary $glossary, ProductAreaList $areas, callable $fail): ?TermStatusEntry
    {
        if (! is_array($fields) || array_is_list($fields)) {
            $fail('give its fields under it: '.implode(', ', self::FIELDS));

            return null;
        }

        $problems = 0;
        $problem = function (string $message) use ($fail, &$problems): void {
            $fail($message);
            $problems++;
        };

        foreach (array_keys($fields) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                $problem("{$field} is not a Term Status field (known fields: ".implode(', ', self::FIELDS).')');
            }
        }

        $statusValue = $fields['status'] ?? null;
        $status = is_string($statusValue) ? TermStatus::tryFrom($statusValue) : null;
        if ($statusValue === null || $statusValue === '') {
            $problem('status is missing: Published, Internal or Not built yet');
        } elseif ($status === null) {
            $allowed = implode(', ', array_map(fn (TermStatus $case): string => $case->value, TermStatus::cases()));
            $problem('status "'.(is_scalar($statusValue) ? $statusValue : get_debug_type($statusValue))."\" must be one of: {$allowed}");
        }

        $area = $fields['product_area'] ?? null;
        $area = is_string($area) ? trim($area) : null;
        if ($area === null || $area === '') {
            $problem('product_area is missing: the Product Area whose Articles first use the term');
        } elseif ($status === TermStatus::Published && $areas->exists && ! $areas->has($area)) {
            // A term not built yet may belong to a menu the app doesn't have yet (Outbound, say).
            $problem("product_area \"{$area}\" is not on the Product Area list (".ProductAreaList::FILE.')');
        }

        $ahead = $fields['ahead'] ?? false;
        if (! is_bool($ahead)) {
            $problem('ahead must be true, or left out');
        } elseif ($ahead && $status !== null && $status !== TermStatus::Published) {
            $problem('only a Published term can be marked Ahead');
        }

        $fingerprint = $fields['fingerprint'] ?? null;
        $current = $glossary?->terms[$term] ?? null;
        $set = $current === null ? '' : ': set fingerprint: '.$current->fingerprint();
        if ($fingerprint === null || $fingerprint === '') {
            $problem('fingerprint is missing'.$set);
        } elseif (! is_string($fingerprint) || preg_match('/\A[0-9a-f]{12}\z/', $fingerprint) !== 1) {
            $problem('fingerprint must be the 12 hex digits the Glossary Check gives'.$set);
        }

        $stockistFingerprint = $fields['stockist_fingerprint'] ?? null;
        if ($stockistFingerprint !== null && (! is_string($stockistFingerprint) || preg_match('/\A[0-9a-f]{12}\z/', $stockistFingerprint) !== 1)) {
            $problem('stockist_fingerprint must be the 12 hex digits the Glossary Check gives, or left out');
        }

        if ($problems > 0 || $status === null || $area === null || ! is_bool($ahead) || ! is_string($fingerprint)) {
            return null;
        }

        return new TermStatusEntry($term, $status, $area, $ahead, $fingerprint, $line, is_string($stockistFingerprint) ? $stockistFingerprint : null);
    }

    /** @param  list<string>  $lines */
    private static function lineOf(array $lines, string $term): int
    {
        foreach ($lines as $index => $line) {
            if (preg_match('/^([\'"]?)'.preg_quote($term, '/').'\1\s*:/', $line) === 1) {
                return $index + 1;
            }
        }

        return 1;
    }
}

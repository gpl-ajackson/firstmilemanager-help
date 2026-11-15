<?php

namespace App\Content;

/**
 * One Change Summary (plan, S7 step 5): one or two plain sentences on what a reader can now do
 * or will notice after a FMM Release, with its Product Area, the Team Roles it affects,
 * whether it changes what clients see ("Affects your clients") and the Articles it changed.
 *
 * A Help Proposal writes one as a file in the Next Release folder (ChangeSummarySource); the
 * Release Note it joins holds the same fields as one of its changes (ReleaseNoteSource).
 */
final readonly class ChangeSummary
{
    use HeaderData;

    /** The fields a Change Summary carries, in the order writers write them; its sentences are its text. */
    public const FIELDS = ['product_area', 'team_roles', 'affects_clients', 'articles'];

    public const OPTIONAL_FIELDS = ['articles'];

    private const NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    public function __construct(
        public string $productArea,
        /** @var list<string> The Team Roles it affects. */
        public array $teamRoles,
        /** Whether it changes what clients see, so Account Managers know what to tell them. */
        public bool $affectsClients,
        /** @var list<string> Article Names of the Articles it changed, in the writer's order. */
        public array $articles,
        /** Its sentences, in Markdown (links by name allowed). */
        public string $text,
    ) {}

    /**
     * A Change Summary from its fields and sentences, or null after reporting what is wrong.
     * $known lists every field the caller allows (an unknown one is refused).
     *
     * @param  array<mixed>  $fields
     * @param  list<string>  $known
     * @param  callable(string, string): void  $problem
     */
    public static function from(array $fields, string $text, array $known, callable $problem): ?self
    {
        $before = 0;
        $found = function (string $field, string $message) use ($problem, &$before): void {
            $before++;
            $problem($field, $message);
        };

        foreach (array_keys($fields) as $field) {
            if (! in_array($field, $known, true)) {
                $found((string) $field, 'is not a Change Summary field (known fields: '.implode(', ', $known).')');
            }
        }
        foreach (array_diff(self::FIELDS, self::OPTIONAL_FIELDS) as $field) {
            if (self::isMissing($fields, $field)) {
                $found($field, 'is missing');
            }
        }

        $productArea = self::text($fields, 'product_area', null, $found);
        $teamRoles = self::names($fields, 'team_roles', null, '', $found);
        $articles = self::names($fields, 'articles', self::NAME_PATTERN, 'must be an Article Name', $found);

        $affects = $fields['affects_clients'] ?? null;
        if ($affects !== null && ! is_bool($affects)) {
            $found('affects_clients', 'must be true (it changes what clients see) or false');
        }

        $text = trim($text);
        if ($text === '') {
            $found('text', 'is missing: say what a reader can now do or will notice, in one or two plain sentences');
        }

        if ($before > 0 || $productArea === null || ! is_bool($affects)) {
            return null;
        }

        return new self($productArea, $teamRoles, $affects, $articles, $text);
    }
}

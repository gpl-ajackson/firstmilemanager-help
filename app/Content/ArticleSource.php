<?php

namespace App\Content;

use Carbon\CarbonImmutable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One Article file, read and checked: its header data (YAML front matter between two "---"
 * lines) and its Markdown body. The Article Name is the file's name without ".md".
 *
 * read() reports every problem it finds to $problems and returns null when there was one, so
 * a rebuild can list all of them at once.
 */
final readonly class ArticleSource
{
    use HeaderData;

    /** The header data fields an Article may carry, in the order writers write them. */
    public const FIELDS = [
        'title', 'summary', 'type', 'product_area', 'audience_tier', 'owner', 'status', 'checked_on',
        'next_review_on', 'team_roles', 'source_references', 'related_articles', 'path_position', 'messages',
        'replacement', 'what_changed', 'former_addresses', 'feature', 'joint_only',
    ];

    /**
     * Fields an Article may leave out; any rule that requires one in some cases is checked on its
     * own: Team Roles on Staff Articles, related articles on every type but Getting-started,
     * the place in the path on Getting-started, messages on Troubleshooting, and what changed on
     * a Retired Article with no replacement.
     */
    public const OPTIONAL_FIELDS = ['team_roles', 'related_articles', 'path_position', 'messages', 'replacement', 'what_changed', 'former_addresses', 'feature', 'joint_only'];

    /** A Retired Article's notice says what changed in a short sentence or two. */
    public const WHAT_CHANGED_MAX = 300;

    /** What an Article Name looks like; an address is /articles/<name>. */
    private const NAME_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const TITLE_MAX = 60;

    public const SUMMARY_MAX = 160;

    /** The next review date is at most six months after Checked On. */
    public const REVIEW_MONTHS_MAX = 6;

    public const RELATED_MIN = 2;

    public const RELATED_MAX = 5;

    /** A plan or spec path in the main project, an ADR, or a main-project issue or pull request. */
    private const SOURCE_REFERENCE_PATTERN = '/\A(?:ADR-\d{4}|fmm#\d+|[\w.-]+(?:\/[\w.-]+)*\.md)\z/';

    private function __construct(
        public string $name,
        public string $file,
        public string $title,
        public string $summary,
        public ArticleType $type,
        public string $productArea,
        public AudienceTier $audienceTier,
        public string $owner,
        public ArticleStatus $status,
        public CarbonImmutable $checkedOn,
        public CarbonImmutable $nextReviewOn,
        /** @var list<string> Team Roles; never shown on a Client-tier Article. */
        public array $teamRoles,
        /** @var list<string> */
        public array $sourceReferences,
        /** @var list<string> Article Names, in the writer's order. */
        public array $relatedArticles,
        /** The place in the Getting-started Path; null on every other type. */
        public ?int $pathPosition,
        /** @var list<string> Troubleshooting messages, word for word, Placeholders in [brackets]. */
        public array $messages,
        public string $body,
        /** The Article Name a Retired Article redirects to, if it has a replacement. */
        public ?string $replacement = null,
        /** What a Retired Article with no replacement tells readers on its notice. */
        public ?string $whatChanged = null,
        /** @var list<string> Addresses this Article was once at, each redirecting here for good. */
        public array $formerAddresses = [],
        /** The file's line on which the body starts, so a problem in the body names its line. */
        public int $bodyLine = 1,
        /** @var list<string> The header data's lines, from the file's second line. */
        private array $headerLines = [],
        /** The feature it is about, as content/features.yaml names it; it shows once that is on the demo. */
        public ?string $feature = null,
        /**
         * Joint-only (ADR-0006): about Stockist, for readers at a Joint Operator. It is never
         * listed, searched or in the sitemap, and says "noindex", so a reader at an FMM-only
         * Operator never meets it; Joint-only pages link to it and its address opens it.
         */
        public bool $jointOnly = false,
        /** Published, but its feature isn't on the demo yet, so it waits hidden as a Draft does. */
        public bool $waitingForDemo = false,
    ) {}

    /** This Article as a rebuild stores it while its feature isn't on the demo: hidden, as a Draft is. */
    public function waitingForDemo(): self
    {
        return new self(
            $this->name, $this->file, $this->title, $this->summary, $this->type, $this->productArea, $this->audienceTier,
            $this->owner, ArticleStatus::Draft, $this->checkedOn, $this->nextReviewOn, $this->teamRoles, $this->sourceReferences,
            $this->relatedArticles, $this->pathPosition, $this->messages, $this->body, $this->replacement, $this->whatChanged,
            $this->formerAddresses, $this->bodyLine, $this->headerLines, $this->feature, $this->jointOnly, waitingForDemo: true,
        );
    }

    /**
     * The file's line holding one item of a header data list (such as one of the related
     * articles), or the field's own line, or line 1 when neither is found.
     */
    public function lineOfItem(string $field, string $item): int
    {
        $fieldLine = null;
        foreach ($this->headerLines as $index => $line) {
            if ($fieldLine === null) {
                if (preg_match('/\A'.preg_quote($field, '/').'\s*:/', $line) === 1) {
                    $fieldLine = $index + 2;
                }
            } elseif (preg_match('/\A\s*-\s*([\'"]?)'.preg_quote($item, '/').'\1\s*(?:#.*)?\z/', $line) === 1) {
                return $index + 2;
            } elseif (preg_match('/\A\S/', $line) === 1) {
                break;
            }
        }

        return $fieldLine ?? 1;
    }

    /** The Article's address: its Article Name never changes (ADR-0001). */
    public function address(): string
    {
        return self::addressOf($this->name);
    }

    public static function addressOf(string $name): string
    {
        return '/articles/'.$name;
    }

    /**
     * @param  string  $path  The file to read.
     * @param  string  $file  Its path as problems name it (relative to the content folder).
     * @param  list<ContentProblem>  $problems
     */
    public static function read(string $path, string $file, array &$problems): ?self
    {
        $before = count($problems);
        $problem = function (string $field, string $message) use ($file, &$problems): void {
            $problems[] = new ContentProblem($file, $field, $message);
        };

        $name = basename($path, '.md');
        if (preg_match('/\A'.self::NAME_PATTERN.'\z/', $name) !== 1) {
            $problem('Article Name', "\"{$name}\" must be lower-case letters and numbers joined by single hyphens");
        }

        $contents = (string) file_get_contents($path);
        if (preg_match('/\A---\R(.*?)\R---(?:\R(.*))?\z/s', $contents, $parts) !== 1) {
            $problem('header data', 'the file must start with header data between two "---" lines');

            return null;
        }

        try {
            $header = Yaml::parse($parts[1], Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            $problem('header data', 'is not valid YAML: '.$e->getMessage());

            return null;
        }
        if (! is_array($header)) {
            $problem('header data', 'must be a list of "field: value" lines');

            return null;
        }

        foreach (array_keys($header) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                $problem((string) $field, 'is not a header data field (known fields: '.implode(', ', self::FIELDS).')');
            }
        }
        foreach (array_diff(self::FIELDS, self::OPTIONAL_FIELDS) as $field) {
            if (self::isMissing($header, $field)) {
                $problem($field, 'is missing');
            }
        }

        $title = self::text($header, 'title', self::TITLE_MAX, $problem);
        $summary = self::text($header, 'summary', self::SUMMARY_MAX, $problem);
        $productArea = self::text($header, 'product_area', null, $problem);
        $type = self::choice($header, 'type', ArticleType::class, $problem);
        $audienceTier = self::choice($header, 'audience_tier', AudienceTier::class, $problem);
        $status = self::choice($header, 'status', ArticleStatus::class, $problem);
        $owner = self::text($header, 'owner', null, $problem);
        if ($owner !== null && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}\z/', $owner) !== 1) {
            $problem('owner', "\"{$owner}\" must be the Article Owner's GitHub login");
            $owner = null;
        }
        $checkedOn = self::date($header, 'checked_on', $parts[1], $problem);
        $nextReviewOn = self::date($header, 'next_review_on', $parts[1], $problem);
        if ($checkedOn !== null && $nextReviewOn !== null) {
            $latest = $checkedOn->addMonthsNoOverflow(self::REVIEW_MONTHS_MAX);
            if ($nextReviewOn->lessThanOrEqualTo($checkedOn)) {
                $problem('next_review_on', "must be after checked_on ({$checkedOn->toDateString()})");
            } elseif ($nextReviewOn->greaterThan($latest)) {
                $problem('next_review_on', 'must be six months at most after checked_on ('.$latest->toDateString().' at the latest)');
            }
        }

        $teamRoles = self::names($header, 'team_roles', null, 'must be a list of names', $problem);
        if ($teamRoles === [] && $audienceTier === AudienceTier::Staff && self::isMissing($header, 'team_roles')) {
            $problem('team_roles', 'is missing (Staff Articles name the Team Roles they are for)');
        }

        $sourceReferences = self::names($header, 'source_references', self::SOURCE_REFERENCE_PATTERN,
            'must be a plan or spec path ending ".md", ADR-nnnn or fmm#n', $problem);

        $relatedArticles = self::names($header, 'related_articles', '/\A'.self::NAME_PATTERN.'\z/', 'must be an Article Name', $problem);
        if (self::isMissing($header, 'related_articles')) {
            if ($type !== null && $type !== ArticleType::GettingStarted) {
                $problem('related_articles', 'is missing');
            }
        } elseif (is_array($header['related_articles'])) {
            $count = count($header['related_articles']);
            if ($count < self::RELATED_MIN || $count > self::RELATED_MAX) {
                $problem('related_articles', "must list two to five Article Names (it has {$count})");
            }
        }

        $feature = self::isMissing($header, 'feature') ? null : self::text($header, 'feature', null, $problem);
        $jointOnly = false;
        if (! self::isMissing($header, 'joint_only')) {
            if (! is_bool($header['joint_only'])) {
                $problem('joint_only', 'must be true or false');
            } else {
                $jointOnly = $header['joint_only'];
            }
        }
        if ($jointOnly && $audienceTier === AudienceTier::Client) {
            $problem('joint_only', 'can\'t be on a Client-tier Article: an Operator\'s clients never meet Stockist');
        }
        if ($jointOnly && $type === ArticleType::GettingStarted) {
            $problem('joint_only', 'can\'t be on a Getting-started Article: every reader follows the path');
        }
        if (! $jointOnly && str_contains($name, 'stockist')) {
            $problem('Article Name', "\"{$name}\" must not contain \"stockist\": only a Joint-only Article (joint_only: true) names Stockist, since a reader at an FMM-only Operator never sees it");
        }

        $pathPosition = self::pathPosition($header, $type, $problem);
        $messages = self::messages($header, $type, $problem);

        [$replacement, $whatChanged] = self::retirement($header, $name, $status, $problem);
        $formerAddresses = self::formerAddresses($header, $name, $problem);

        $body = trim($parts[2] ?? '');
        // Line 1 is the opening "---", then the header data, the closing "---" and any blank lines.
        $headerLines = preg_split('/\R/', $parts[1]) ?: [];
        $bodyLine = count($headerLines) + 3 + substr_count(substr($parts[2] ?? '', 0, strspn($parts[2] ?? '', " \t\r\n")), "\n");
        if ($body === '') {
            $problem('body', 'the Article has no text after its header data');
        }

        if (count($problems) > $before
            || $title === null || $summary === null || $productArea === null || $owner === null
            || $type === null || $audienceTier === null || $status === null || $checkedOn === null
            || $nextReviewOn === null) {
            return null;
        }

        return new self(
            $name, $file, $title, $summary, $type, $productArea, $audienceTier, $owner, $status, $checkedOn,
            $nextReviewOn, $teamRoles, $sourceReferences, $relatedArticles, $pathPosition, $messages, $body,
            $replacement, $whatChanged, $formerAddresses, $bodyLine, $headerLines, $feature, $jointOnly,
        );
    }

    /**
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     */
    private static function pathPosition(array $header, ?ArticleType $type, callable $problem): ?int
    {
        $missing = self::isMissing($header, 'path_position');
        if ($type !== ArticleType::GettingStarted) {
            if (! $missing && $type !== null) {
                $problem('path_position', 'belongs only on Getting-started Articles');
            }

            return null;
        }
        if ($missing) {
            $problem('path_position', 'is missing (every Getting-started Article has its place in the path)');

            return null;
        }
        $value = $header['path_position'];
        if (! is_int($value) || $value < 1) {
            $problem('path_position', 'must be a whole number from 1');

            return null;
        }

        return $value;
    }

    /**
     * A Troubleshooting Article's messages, each word for word with its Placeholders in square
     * brackets ("[client] is on hold").
     *
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     * @return list<string>
     */
    private static function messages(array $header, ?ArticleType $type, callable $problem): array
    {
        $missing = self::isMissing($header, 'messages');
        if ($type !== ArticleType::Troubleshooting) {
            if (! $missing && $type !== null) {
                $problem('messages', 'belong only on Troubleshooting Articles');
            }

            return [];
        }
        if ($missing) {
            $problem('messages', 'is missing (a Troubleshooting Article lists every message it explains)');

            return [];
        }
        $value = $header['messages'];
        if (! is_array($value) || ! array_is_list($value)) {
            $problem('messages', 'must be a list, one "- " line each');

            return [];
        }

        $messages = [];
        foreach ($value as $message) {
            if (! is_string($message) || trim($message) === '') {
                $problem('messages', 'must be a list of messages, each written word for word');

                continue;
            }
            $message = trim($message);
            $outside = (string) preg_replace('/\[[^\[\]]*\]/', '', $message);
            if (preg_match('/\[\s*\]/', $message) === 1) {
                $problem('messages', "\"{$message}\" has an empty Placeholder");
            } elseif (str_contains($outside, '[')) {
                $problem('messages', "\"{$message}\" has a Placeholder without its closing \"]\"");
            } elseif (str_contains($outside, ']')) {
                $problem('messages', "\"{$message}\" has a \"]\" without its opening \"[\"");
            } else {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * A Retired Article either names its replacement, which its address redirects to, or says
     * what changed, which its notice shows. Neither field belongs on any other Article. Whether
     * the replacement exists and is Published is checked once every file is read.
     *
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     * @return array{?string, ?string}
     */
    private static function retirement(array $header, string $name, ?ArticleStatus $status, callable $problem): array
    {
        $replacement = self::text($header, 'replacement', null, $problem);
        $whatChanged = self::text($header, 'what_changed', self::WHAT_CHANGED_MAX, $problem);
        if ($status === null) {
            return [$replacement, $whatChanged];
        }

        if ($status !== ArticleStatus::Retired) {
            foreach (['replacement' => $replacement, 'what_changed' => $whatChanged] as $field => $value) {
                if ($value !== null) {
                    $problem($field, "only a Retired Article has one (this Article is {$status->value})");
                }
            }

            return [null, null];
        }

        if ($replacement !== null) {
            if (preg_match('/\A'.self::NAME_PATTERN.'\z/', $replacement) !== 1) {
                $problem('replacement', "\"{$replacement}\" must be the Article Name of the Article that replaces this one");
            } elseif ($replacement === $name) {
                $problem('replacement', 'a Retired Article can\'t replace itself');
            }
            if ($whatChanged !== null) {
                $problem('what_changed', 'readers are redirected to the replacement and never see it; leave it out');
            }
        } elseif (in_array($header['what_changed'] ?? null, [null, ''], true)) {
            $problem('what_changed', 'is missing: a Retired Article with no replacement shows readers what changed');
        }

        return [$replacement, $whatChanged];
    }

    /**
     * Addresses this Article was once at, written as /articles/<name>. Whether another Article
     * uses or claims one is checked once every file is read.
     *
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     * @return list<string>
     */
    private static function formerAddresses(array $header, string $name, callable $problem): array
    {
        $value = $header['former_addresses'] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            $problem('former_addresses', 'must be a list, one "- /articles/<name>" line for each address');

            return [];
        }

        $addresses = [];
        foreach ($value as $address) {
            if (! is_string($address) || preg_match('#\A/articles/'.self::NAME_PATTERN.'\z#', $address) !== 1) {
                $problem('former_addresses', '"'.(is_scalar($address) ? $address : get_debug_type($address)).'" must be an Article\'s address, written /articles/<name>');
            } elseif ($address === self::addressOf($name)) {
                $problem('former_addresses', "\"{$address}\" is this Article's own address");
            } elseif (in_array($address, $addresses, true)) {
                $problem('former_addresses', "\"{$address}\" is listed twice");
            } else {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}

<?php

namespace App\LaunchChecks\Findability;

use App\LaunchChecks\Findings;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Findability Set, `findability-set.yaml` in the content folder: questions in readers' own
 * words, each with the address of the answer search must show in its top three results.
 *
 *     - question: bin full
 *       answer: /articles/put-stock-away#location-full     an Article's Section
 *     - question: how do I stop a client sending stock
 *       answer: /articles/put-a-client-on-hold             an Article
 *     - question: bin
 *       answer: /glossary/location                         a Glossary Entry (or /client-glossary/<term>)
 *
 * Each problem with the file fails Launch Check 10 as findability.format, naming the entry's line.
 */
final readonly class FindabilitySet
{
    public const FILE = 'findability-set.yaml';

    public const FIELDS = ['question', 'answer'];

    private const ADDRESS = '#\A/(?<kind>articles|glossary|client-glossary)/(?<name>[a-z0-9]+(?:-[a-z0-9]+)*)(?:\#(?<section>[a-z0-9]+(?:-[a-z0-9]+)*))?\z#';

    /** @param  list<NamedAnswer>  $questions  the questions that read cleanly */
    private function __construct(
        public bool $exists,
        public bool $isEmpty,
        public array $questions,
    ) {}

    public static function read(string $contentPath, Findings $findings): self
    {
        $path = $contentPath.'/'.self::FILE;
        if (! is_file($path)) {
            return new self(false, true, []);
        }

        $contents = (string) file_get_contents($path);
        try {
            $list = Yaml::parse($contents);
        } catch (ParseException $e) {
            $findings->fail('findability.format', self::FILE, max(1, $e->getParsedLine()), 'is not valid YAML: '.$e->getMessage());

            return new self(true, false, []);
        }
        if ($list === null || $list === []) {
            return new self(true, true, []);
        }
        if (! is_array($list) || ! array_is_list($list)) {
            $findings->fail('findability.format', self::FILE, 1, 'must be a list of questions, each "- question: <a reader\'s words>" with "answer: <address>" under it');

            return new self(true, false, []);
        }

        $lines = self::entryLines($contents);
        $questions = [];
        foreach ($list as $index => $entry) {
            $line = $lines[$index] ?? 1;
            $question = self::question($entry, $line, fn (string $message) => $findings->fail('findability.format', self::FILE, $line, $message));
            if ($question !== null) {
                $questions[] = $question;
            }
        }

        return new self(true, false, $questions);
    }

    /** @param  callable(string): void  $fail */
    private static function question(mixed $entry, int $line, callable $fail): ?NamedAnswer
    {
        if (! is_array($entry) || array_is_list($entry)) {
            $fail('each entry needs "question: <a reader\'s words>" and "answer: <address>"');

            return null;
        }

        $problems = 0;
        foreach (array_keys($entry) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                $fail("{$field} is not a Findability Set field (known fields: ".implode(', ', self::FIELDS).')');
                $problems++;
            }
        }

        $question = $entry['question'] ?? null;
        $question = is_scalar($question) ? trim((string) $question) : '';
        if ($question === '') {
            $fail('question is missing: the question in a reader\'s own words');
            $problems++;
        }

        $answer = $entry['answer'] ?? null;
        $quoted = $question === '' ? '' : "\"{$question}\": ";
        if (! is_string($answer) || trim($answer) === '') {
            $fail("{$quoted}answer is missing: /articles/<name>, /articles/<name>#<Section Name>, /glossary/<term> or /client-glossary/<term>");

            return null;
        }
        $answer = trim($answer);
        if (preg_match(self::ADDRESS, $answer, $parts) !== 1 || ($parts['kind'] !== 'articles' && ($parts['section'] ?? '') !== '')) {
            $fail("{$quoted}answer \"{$answer}\" must be the answer's address: /articles/<name>, /articles/<name>#<Section Name>, /glossary/<term> or /client-glossary/<term>");

            return null;
        }

        if ($problems > 0) {
            return null;
        }

        $section = $parts['section'] ?? '';

        return new NamedAnswer($question, $answer, $parts['kind'], $parts['name'], $section === '' ? null : $section, $line);
    }

    /**
     * The line of each top-level list item, in order (YAML gives values without lines).
     *
     * @return list<int>
     */
    private static function entryLines(string $contents): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (preg_match('/^-(\s|$)/', $line) === 1) {
                $lines[] = $index + 1;
            }
        }

        return $lines;
    }
}

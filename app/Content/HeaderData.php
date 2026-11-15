<?php

namespace App\Content;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reading header data (YAML front matter between two "---" lines), shared by every kind of
 * content file: Articles and Glossary Entries. Each helper reports what is wrong through
 * $problem(field, message) and returns null (or an empty list) for a value it can't use.
 */
trait HeaderData
{
    /**
     * A file's header data and the text after it: [header data, its YAML, the rest], or null
     * after reporting why the file can't be read.
     *
     * @param  callable(string, string): void  $problem
     * @return array{array<mixed>, string, string}|null
     */
    private static function frontMatter(string $contents, callable $problem): ?array
    {
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

        return [$header, $parts[1], $parts[2] ?? ''];
    }

    /**
     * @param  array<mixed>  $header
     */
    private static function isMissing(array $header, string $field): bool
    {
        return ! array_key_exists($field, $header) || in_array($header[$field], [null, '', []], true);
    }

    /**
     * A list of distinct, non-empty pieces of text, each matching $pattern when one is given.
     *
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     * @return list<string>
     */
    private static function names(array $header, string $field, ?string $pattern, string $says, callable $problem): array
    {
        if (self::isMissing($header, $field)) {
            return [];
        }
        $value = $header[$field];
        if (! is_array($value) || ! array_is_list($value)) {
            $problem($field, 'must be a list, one "- " line each');

            return [];
        }

        $names = [];
        foreach ($value as $item) {
            $item = is_string($item) ? trim($item) : null;
            if ($item === null || $item === '') {
                $problem($field, 'must be a list of names');

                continue;
            }
            if ($pattern !== null && preg_match($pattern, $item) !== 1) {
                $problem($field, "\"{$item}\" {$says}");

                continue;
            }
            if (in_array($item, $names, true)) {
                $problem($field, "\"{$item}\" is listed twice");

                continue;
            }
            $names[] = $item;
        }

        return $names;
    }

    /**
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     */
    private static function text(array $header, string $field, ?int $max, callable $problem): ?string
    {
        $value = $header[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            $problem($field, 'must be text');

            return null;
        }
        $value = trim($value);
        if ($max !== null && mb_strlen($value) > $max) {
            $problem($field, "must be {$max} characters at most (it has ".mb_strlen($value).')');

            return null;
        }

        return $value;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  array<mixed>  $header
     * @param  class-string<T>  $enum
     * @param  callable(string, string): void  $problem
     * @return T|null
     */
    private static function choice(array $header, string $field, string $enum, callable $problem): ?BackedEnum
    {
        $value = $header[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        $choice = is_string($value) ? $enum::tryFrom($value) : null;
        if ($choice === null) {
            $allowed = implode(', ', array_map(fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));
            $problem($field, '"'.(is_scalar($value) ? $value : get_debug_type($value))."\" must be one of: {$allowed}");
        }

        return $choice;
    }

    /**
     * YAML reads an unquoted 2026-02-30 as 2 March, so the date is checked as the writer typed it.
     *
     * @param  array<mixed>  $header
     * @param  callable(string, string): void  $problem
     */
    private static function date(array $header, string $field, string $yaml, callable $problem): ?CarbonImmutable
    {
        $value = $header[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface
            && preg_match('/^'.preg_quote($field, '/').':\s*([^\s#]+)/m', $yaml, $typed) === 1) {
            $value = $typed[1];
        }
        if (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($date !== null && $date->format('Y-m-d') === $value) {
                return $date;
            }
        }
        $problem($field, '"'.(is_scalar($value) ? $value : get_debug_type($value)).'" must be a date written YYYY-MM-DD');

        return null;
    }
}

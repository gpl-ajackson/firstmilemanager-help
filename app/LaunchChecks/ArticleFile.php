<?php

namespace App\LaunchChecks;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * One Article file as the Launch Checks read it: its header data as the writer typed it, the
 * line each field sits on, and its body with the line it starts on. Whether the header data is
 * valid is the content library's to say (ArticleSource); this only reads what is there.
 * Glossary Entry files have the same shape and are read the same way
 * (CheckContext::glossaryEntries()).
 */
final readonly class ArticleFile
{
    /**
     * @param  array<mixed>  $header  The header data, or [] when it can't be read.
     * @param  list<string>  $headerLines  The header data's lines; line 2 of the file is the first.
     */
    private function __construct(
        public string $name,
        public string $file,
        public array $header,
        private array $headerLines,
        public string $body,
        public int $bodyStartLine,
        public int $lastLine,
    ) {}

    /** @param  string  $file  Its path as findings name it (relative to the content folder). */
    public static function read(string $path, string $file): self
    {
        $contents = (string) file_get_contents($path);
        $lastLine = max(1, substr_count(rtrim($contents, "\r\n"), "\n") + 1);

        if (preg_match('/\A---\R(.*?)\R---(?:\R|\z)/s', $contents, $parts) !== 1) {
            return new self(basename($path, '.md'), $file, [], [], $contents, 1, $lastLine);
        }

        try {
            $header = Yaml::parse($parts[1]);
        } catch (ParseException) {
            $header = [];
        }

        return new self(
            name: basename($path, '.md'),
            file: $file,
            header: is_array($header) ? $header : [],
            headerLines: preg_split('/\R/', $parts[1]) ?: [],
            body: substr($contents, strlen($parts[0])),
            bodyStartLine: substr_count($parts[0], "\n") + 1,
            lastLine: $lastLine,
        );
    }

    /** A header field's value as text, or null when it is missing or not text. */
    public function text(string $field): ?string
    {
        $value = $this->header[$field] ?? null;

        return is_string($value) ? trim($value) : null;
    }

    public function has(string $field): bool
    {
        $value = $this->header[$field] ?? null;

        return $value !== null && $value !== '' && $value !== [];
    }

    /** The line a header field is written on; line 1 (the opening "---") when it isn't there. */
    public function lineOf(string $field): int
    {
        foreach ($this->headerLines as $index => $line) {
            if (preg_match('/^'.preg_quote($field, '/').'\s*:/', $line) === 1) {
                return $index + 2;
            }
        }

        return 1;
    }

    /**
     * The line, at or after a field's own, that holds the start of some text written under it
     * (a status value's meaning, say); the field's own line when it can't be found.
     */
    public function lineOfText(string $field, string $text): int
    {
        $fieldLine = $this->lineOf($field);
        $start = mb_substr(trim((string) preg_replace('/\s+/', ' ', $text)), 0, 20);
        foreach (array_slice($this->headerLines, $fieldLine - 1, null, true) as $index => $line) {
            if ($start !== '' && str_contains($line, $start)) {
                return $index + 2;
            }
        }

        return $fieldLine;
    }

    /** The line of one item in a header field's list, or the field's own line. */
    public function lineOfItem(string $field, string $item): int
    {
        $fieldLine = $this->lineOf($field);
        foreach (array_slice($this->headerLines, $fieldLine - 1, null, true) as $index => $line) {
            if (preg_match('/^\s*-\s*([\'"]?)'.preg_quote($item, '/').'\1\s*(#.*)?$/', $line) === 1) {
                return $index + 2;
            }
            if (preg_match('/^\S/', $line) === 1) {
                break;
            }
        }

        return $fieldLine;
    }
}

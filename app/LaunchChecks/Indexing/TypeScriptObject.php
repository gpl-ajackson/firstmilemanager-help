<?php

namespace App\LaunchChecks\Indexing;

/**
 * Reads an exported object literal from TypeScript source without running it, as the marketing
 * site's src/data/site.ts writes its facts:
 *
 *     export const site = {
 *       name: 'Stockist WMS',
 *       description:
 *         'Stockist WMS is ...',
 *       company: { legalName: '...' },
 *       helpUrl: null as string | null,
 *     };
 *
 * Each property becomes a string (one piece of quoted text, with or without `as ...` after
 * it), a nested array (an object literal), or null (anything else: numbers, booleans, arrays,
 * text joined from pieces or with ${...} in it). Comments are skipped.
 */
final class TypeScriptObject
{
    private int $at = 0;

    private function __construct(private readonly string $source) {}

    /**
     * The object `export const <name> = { ... }` in $source, or null when there is none or it
     * can't be read.
     *
     * @return array<string, mixed>|null
     */
    public static function exported(string $source, string $name): ?array
    {
        if (preg_match('/\bexport\s+const\s+'.preg_quote($name, '/').'\s*(?::[^=]+)?=\s*\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $reader = new self($source);
        $reader->at = $match[0][1] + strlen($match[0][0]) - 1;

        return $reader->object();
    }

    /** @return array<string, mixed>|null */
    private function object(): ?array
    {
        $this->at++; // {
        $properties = [];
        while (true) {
            $this->skipSpace();
            $char = $this->source[$this->at] ?? null;
            if ($char === null) {
                return null;
            }
            if ($char === '}') {
                $this->at++;

                return $properties;
            }
            $key = $this->key();
            if ($key === null) {
                return null;
            }
            $this->skipSpace();
            if (($this->source[$this->at] ?? null) !== ':') {
                // A shorthand property or a method: not a fact.
                $this->skipValue();
            } else {
                $this->at++;
                $properties[$key] = $this->value();
            }
            $this->skipSpace();
            if (($this->source[$this->at] ?? null) === ',') {
                $this->at++;
            }
        }
    }

    private function key(): ?string
    {
        $char = $this->source[$this->at];
        if ($char === "'" || $char === '"') {
            return $this->string();
        }
        if (preg_match('/\G[A-Za-z_$][\w$]*/', $this->source, $match, 0, $this->at) !== 1) {
            return null;
        }
        $this->at += strlen($match[0]);

        return $match[0];
    }

    /** @return string|array<string, mixed>|null */
    private function value(): string|array|null
    {
        $this->skipSpace();
        $char = $this->source[$this->at] ?? null;
        if ($char === '{') {
            $object = $this->object();
            $this->skipValue();

            return $object;
        }
        if ($char !== "'" && $char !== '"' && $char !== '`') {
            $this->skipValue();

            return null;
        }

        $text = $this->string();
        $this->skipSpace();
        $next = $this->source[$this->at] ?? '';
        $rest = $this->skipValue();
        // Only `'text'` or `'text' as ...` is one piece of text.
        if ($text === null || ($next !== ',' && $next !== '}' && ! str_starts_with(ltrim($rest), 'as '))) {
            return null;
        }

        return $text;
    }

    /** A quoted string at the cursor, its escapes undone; null for a template with ${...} in it. */
    private function string(): ?string
    {
        $quote = $this->source[$this->at++];
        $text = '';
        $length = strlen($this->source);
        while ($this->at < $length) {
            $char = $this->source[$this->at++];
            if ($char === '\\') {
                $escaped = $this->at < $length ? $this->source[$this->at++] : '';
                $text .= match ($escaped) {
                    'n' => "\n",
                    't' => "\t",
                    default => $escaped,
                };
            } elseif ($char === $quote) {
                return $quote === '`' && str_contains($text, '${') ? null : $text;
            } else {
                $text .= $char;
            }
        }

        return null;
    }

    /** Moves past the rest of a value, up to the , or } that ends it, and returns what it passed. */
    private function skipValue(): string
    {
        $from = $this->at;
        $depth = 0;
        $length = strlen($this->source);
        while ($this->at < $length) {
            $this->skipSpace();
            $char = $this->source[$this->at] ?? '';
            if ($depth === 0 && ($char === ',' || $char === '}')) {
                break;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $this->string();

                continue;
            }
            $depth += match ($char) {
                '{', '[', '(' => 1,
                '}', ']', ')' => -1,
                default => 0,
            };
            $this->at++;
        }

        return substr($this->source, $from, $this->at - $from);
    }

    private function skipSpace(): void
    {
        while (preg_match('~\G(?:\s+|//[^\n]*|/\*.*?\*/)~s', $this->source, $match, 0, $this->at) === 1) {
            $this->at += strlen($match[0]);
        }
    }
}

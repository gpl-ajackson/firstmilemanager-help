<?php

namespace App\LaunchChecks\SourceReferences;

/**
 * A main-project plan's or spec's "Help content" section (the main project's
 * docs/agents/help-content.md), as check 2 reads it back: the help it lists by address,
 * `/articles/<name>` (optionally `#<Section Name>`), `/glossary/<term>` and
 * `/client-glossary/<term>`, and the Articles not written yet, `new: "<proposed title>"` (several
 * titles may follow one `new:`). The rest of the section (help links, the Change Summary) is
 * prose for people and isn't read.
 */
final readonly class HelpContentSection
{
    /** An address to a page of the help centre; not a support-site file path (content/articles/x.md). */
    private const ADDRESS = '~(?<!content)/(articles|glossary|client-glossary)/([a-z0-9]+(?:-[a-z0-9]+)*)(?:#([a-z0-9]+(?:-[a-z0-9]+)*))?(?![\w-])~';

    private const HEADING = '/^ {0,3}(#{1,6})\s+(.*?)\s*#*\s*$/';

    private const FENCE = '/^ {0,3}(```|~~~)/';

    /**
     * @param  array<int, string>  $lines  The section's lines, keyed by their line number in the file or body.
     */
    private function __construct(private array $lines) {}

    /** The Help content section of a plan or spec, or null when it has none. */
    public static function of(string $markdown): ?self
    {
        $section = self::find(preg_split('/\R/', $markdown) ?: []);

        return $section === null ? null : new self($section['lines']);
    }

    /**
     * A plan or spec without its Help content section (heading and all), so the Help Drift
     * Report can tell a change to what it says from a Help Proposal listing its Articles.
     */
    public static function without(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $section = self::find($lines);
        if ($section === null) {
            return $markdown;
        }
        unset($lines[$section['heading']]);
        foreach (array_keys($section['lines']) as $number) {
            unset($lines[$number - 1]);
        }

        return implode("\n", $lines);
    }

    /**
     * Where the Help content section is: its heading's index and its lines by line number.
     *
     * @param  list<string>  $lines
     * @return array{heading: int, lines: array<int, string>}|null
     */
    private static function find(array $lines): ?array
    {
        $section = null;
        $heading = 0;
        $level = 0;
        $fence = null;
        foreach ($lines as $index => $line) {
            if (preg_match(self::FENCE, $line, $marker) === 1) {
                $fence = $fence === null ? $marker[1] : ($fence === $marker[1] ? null : $fence);
            } elseif ($fence === null && preg_match(self::HEADING, $line, $found) === 1) {
                if ($section !== null && strlen($found[1]) <= $level) {
                    break;
                }
                if ($section === null && strcasecmp($found[2], 'Help content') === 0) {
                    $section = [];
                    $heading = $index;
                    $level = strlen($found[1]);

                    continue;
                }
            }
            if ($section !== null) {
                $section[$index + 1] = $line;
            }
        }

        return $section === null ? null : ['heading' => $heading, 'lines' => $section];
    }

    /**
     * Each address the section lists, in order.
     *
     * @return list<array{kind: string, name: string, section: ?string, written: string, line: int}>
     */
    public function addresses(): array
    {
        $addresses = [];
        foreach ($this->lines as $number => $line) {
            preg_match_all(self::ADDRESS, $line, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $addresses[] = [
                    'kind' => $match[1],
                    'name' => $match[2],
                    'section' => ($match[3] ?? '') === '' ? null : $match[3],
                    'written' => $match[0],
                    'line' => $number,
                ];
            }
        }

        return $addresses;
    }

    /** Whether the section lists an Article by its address (/articles/<name>, any Section of it too). */
    public function listsArticle(string $name): bool
    {
        foreach ($this->addresses() as $address) {
            if ($address['kind'] === 'articles' && $address['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each Article listed as not written yet: `new: "<proposed title>"`.
     *
     * @return list<array{title: string, line: int}>
     */
    public function newArticles(): array
    {
        $new = [];
        foreach ($this->lines as $number => $line) {
            if (preg_match('/\bnew:\s*(.*)$/i', $line, $match) !== 1) {
                continue;
            }
            // A title in straight or curly quotes; each match's last group is its words.
            preg_match_all('/"([^"]+)"|“([^”]+)”/u', $match[1], $quoted, PREG_SET_ORDER);
            $titles = array_map(fn (array $title): string => trim((string) end($title)), $quoted);
            if ($titles === [] && trim($match[1], " \t.,;") !== '') {
                $titles = [trim($match[1], " \t.,;")];
            }
            foreach ($titles as $title) {
                $new[] = ['title' => $title, 'line' => $number];
            }
        }

        return $new;
    }
}

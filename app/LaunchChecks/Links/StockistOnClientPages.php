<?php

namespace App\LaunchChecks\Links;

use App\LaunchChecks\ArticleFile;
use App\LaunchChecks\Findings;
use App\Pictures\PictureLibrary;

/**
 * A Client-tier page never names Stockist (plan rule 6): clients see their courier's help, so it
 * says "your courier". Nor does any page a reader at an FMM-only Operator can meet (ADR-0006):
 * every Article but a Joint-only one, and every Glossary Entry. Anything a reader sees counts: the header data the page shows (title, summary,
 * messages, what changed), the body's words, code and diagrams' text included, each Picture's
 * alt text (from its Recipe) and a web link's address. What readers never see doesn't: Section
 * Names, links by name and Picture Names.
 * "Stockist" is matched in any capitals and inside longer words (firstmilemanager.co.uk).
 */
final class StockistOnClientPages
{
    public const RULE = 'links.stockist';

    /** Header data fields a Client-tier Article's page or notice shows. */
    private const SHOWN_FIELDS = ['title', 'summary', 'messages', 'what_changed'];

    /** A Markdown link or picture: [words](destination "title"). */
    private const LINK = '/(!?)\[([^\]]*)\]\(\s*<?([^\s)>]*)>?(?:\s+("[^"]*"|\'[^\']*\'))?\s*\)/';

    private const WEB = '/\A(?:https?:|mailto:|tel:)/i';

    /** Header data a Client Glossary entry never shows; it shows the rest. */
    private const ENTRY_FIELDS_NOT_SHOWN = ['audience_tier', 'checked_on'];

    /** What a Client-tier page says instead. */
    public const FOR_CLIENTS = 'a Client-tier page never names Stockist: say "your courier"';

    /** What any other page an FMM-only reader can meet does instead. */
    public const FOR_FMM_ONLY = 'a reader at an FMM-only Operator never meets Stockist: put it in a Joint-only Article (joint_only: true)';

    public static function check(ArticleFile $article, Findings $findings, PictureLibrary $pictures, string $instead = self::FOR_CLIENTS): void
    {
        self::checkPage($article, self::SHOWN_FIELDS, $findings, $pictures, $instead);
    }

    /** A Glossary Entry (content/glossary/ or content/client-glossary/<term>.md), read as header data and a body. */
    public static function checkEntry(ArticleFile $entry, Findings $findings, PictureLibrary $pictures, string $instead = self::FOR_CLIENTS): void
    {
        $shown = array_diff(array_map(strval(...), array_keys($entry->header)), self::ENTRY_FIELDS_NOT_SHOWN);
        self::checkPage($entry, array_values($shown), $findings, $pictures, $instead);
    }

    /** @param  list<string>  $shownFields */
    private static function checkPage(ArticleFile $article, array $shownFields, Findings $findings, PictureLibrary $pictures, string $instead): void
    {
        foreach ($shownFields as $field) {
            $values = $article->header[$field] ?? null;
            foreach (is_array($values) ? $values : [$values] as $value) {
                // A list of strings names each item's line; anything deeper names the field's.
                $text = is_array($value) ? implode(' ', array_filter(array_map(fn (mixed $part): string => is_scalar($part) ? (string) $part : '', $value))) : $value;
                if (is_string($text) && self::names($text)) {
                    $line = is_array($values) && is_string($value) ? $article->lineOfItem($field, $value) : $article->lineOf($field);
                    self::fail($findings, $article, $line, 'its '.str_replace('_', ' ', $field), $instead);
                }
            }
        }

        // A Retired Article's body is never shown; its notice shows only the header data above.
        if (($article->header['status'] ?? null) === 'Retired') {
            return;
        }

        foreach (preg_split('/\R/', $article->body) ?: [] as $index => $text) {
            $line = $article->bodyStartLine + $index;
            $where = [];
            $text = (string) preg_replace('/\s*\{#[^{}]*\}\s*$/', '', $text);
            $text = (string) preg_replace_callback(self::LINK, function (array $link) use (&$where, $pictures): string {
                [, $picture, $words, $destination] = $link;
                if ($picture === '!' && str_starts_with($destination, 'picture:')) {
                    // A Picture's alt text is its Recipe's; alt written here is refused by the rebuild.
                    $name = substr($destination, strlen('picture:'));
                    if (self::names($pictures->alt($name) ?? '')) {
                        $where[] = "the alt text of Picture \"{$name}\" (its Recipe)";
                    }
                } elseif (self::names($words)) {
                    $where[] = $picture === '!' ? 'a picture\'s alt text' : 'its words';
                }
                if ($picture !== '!' && preg_match(self::WEB, $destination) === 1 && self::names($destination)) {
                    $where[] = 'a link\'s address';
                }
                if (self::names($link[4] ?? '')) {
                    $where[] = 'its words';
                }

                return ' ';
            }, $text);
            // A reference link's definition: [name]: destination "title".
            if (preg_match('/^\s{0,3}\[[^\]]+\]:\s*<?(\S*?)>?(?:\s+(.*))?$/', $text, $definition) === 1) {
                if (preg_match(self::WEB, $definition[1]) === 1 && self::names($definition[1])) {
                    $where[] = 'a link\'s address';
                }
                $text = $definition[2] ?? '';
            }
            if (self::names($text)) {
                $where[] = 'its words';
            }

            foreach (array_unique($where) as $place) {
                self::fail($findings, $article, $line, $place, $instead);
            }
        }
    }

    private static function names(string $text): bool
    {
        return stripos($text, 'stockist') !== false;
    }

    private static function fail(Findings $findings, ArticleFile $article, int $line, string $place, string $instead): void
    {
        $findings->fail(self::RULE, $article->file, $line, "\"Stockist\" in {$place}; {$instead}");
    }
}

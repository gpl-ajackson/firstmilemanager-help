<?php

namespace App\Site\Http\Controllers;

use App\Content\Article;
use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use App\Site\Addresses;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * /llms.txt (https://llmstxt.org): what the help centre is, from Stockist's facts as the
 * marketing site states them; its Product Areas; every Published Article's title, address and
 * summary under its Product Area; and the Glossaries and Release Notes (spec #30, user story
 * 138). Built from the database copy, so a Draft or Retired Article never appears. Served
 * before going public too, for the Launch Checks at the real address; never indexed itself.
 */
final class LlmsTxt
{
    public function __construct(private readonly ContentLibrary $library) {}

    public function __invoke(): Response
    {
        $site = rtrim(config()->string('help.address'), '/');
        $facts = $this->library->facts();

        $lines = [$facts === null ? '# Help centre' : "# {$facts->name} Help centre", ''];
        if ($facts !== null) {
            $lines[] = "> Help for {$facts->name}, for a courier’s staff and the clients they share it with. {$facts->description}";
            $lines[] = '';
            $lines[] = "Stage: {$facts->stage}.";
            $lines[] = "About {$facts->name}: {$facts->url}";
            $lines[] = '';
        }

        $counts = $this->library->productAreaCounts();
        $lines[] = '## Product Areas';
        $lines[] = '';
        foreach ($counts as $area => $count) {
            $lines[] = self::item($area, $site.Addresses::productArea($area), $count.' '.Str::plural('Article', $count));
        }
        $lines[] = '';

        foreach (array_keys($counts) as $area) {
            $lines[] = "## Articles: {$area}";
            $lines[] = '';
            foreach ($this->library->articlesInArea($area) as $article) {
                $lines[] = self::item($article->title, $site.$article->address(), self::summary($article));
            }
            $lines[] = '';
        }

        $lines[] = '## Glossaries and Release Notes';
        $lines[] = '';
        $lines[] = self::item('Glossary', $site.'/glossary', 'the words First Mile Manager uses, explained for staff');
        $lines[] = self::item('Client Glossary', $site.'/client-glossary', 'the words clients meet, explained for clients');
        $lines[] = self::item('What’s new', $site.'/whats-new', 'a Release Note for each release, newest first');
        foreach ($this->library->releaseNotes() as $note) {
            $lines[] = self::item($note->title(), $site.$note->address(), $note->summary);
        }
        $lines[] = '';

        return response(implode("\n", $lines))
            ->header('Content-Type', 'text/plain; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex');
    }

    private static function summary(Article $article): string
    {
        return $article->summary.($article->audience_tier === AudienceTier::Client ? ' (For clients)' : '');
    }

    /** "- [name](address): notes", llms.txt's list item, with the name's brackets escaped. */
    private static function item(string $name, string $address, string $notes): string
    {
        return '- ['.addcslashes($name, '[]').']('.$address.'): '.$notes;
    }
}

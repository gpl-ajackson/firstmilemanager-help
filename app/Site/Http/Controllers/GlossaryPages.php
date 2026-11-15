<?php

namespace App\Site\Http\Controllers;

use App\Content\AudienceTier;
use App\Content\ContentLibrary;
use Illuminate\Contracts\View\View;

/**
 * The Glossary (/glossary, /glossary/<term>) and the Client Glossary (/client-glossary,
 * /client-glossary/<term>): each tier's entries A to Z, and one entry with the Articles that
 * use it. A client entry is only ever shown at its Client Glossary address, so a staff-only
 * term has no client page. An unknown term is "page not found", after any redirect the content
 * keeps for a renamed term's old address is followed.
 */
final class GlossaryPages
{
    public function __construct(private readonly ContentLibrary $library) {}

    public function staff(): View
    {
        return $this->listing(AudienceTier::Staff);
    }

    public function client(): View
    {
        return $this->listing(AudienceTier::Client);
    }

    public function staffEntry(string $name): View
    {
        return $this->entry($name, AudienceTier::Staff);
    }

    public function clientEntry(string $name): View
    {
        return $this->entry($name, AudienceTier::Client);
    }

    private function listing(AudienceTier $tier): View
    {
        $byLetter = $this->library->glossaryEntries($tier)->groupBy(function ($entry): string {
            $first = mb_strtoupper(mb_substr($entry->term, 0, 1));

            return preg_match('/\A[A-Z]\z/', $first) === 1 ? $first : '0–9';
        });

        return view('site.glossary', ['forClients' => $tier === AudienceTier::Client, 'byLetter' => $byLetter]);
    }

    private function entry(string $name, AudienceTier $tier): View
    {
        $entry = $this->library->glossaryEntry($name, $tier);
        abort_if($entry === null, 404);

        return view('site.glossary-entry', [
            'entry' => $entry,
            'forClients' => $tier === AudienceTier::Client,
            'articles' => $this->library->articlesUsing($entry),
        ]);
    }
}

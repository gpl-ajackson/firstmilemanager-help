<?php

namespace App\Site\Http\Controllers;

use App\Content\ContentLibrary;
use Illuminate\Contracts\View\View;

/**
 * What's new (/whats-new): the Release Notes, newest first, and one Release Note at its dated
 * address (/whats-new/<yyyy-mm-dd>), its changes grouped by Product Area with those that change
 * what clients see marked "Affects your clients" (plan, "Release Notes"). Release Notes are for
 * Staff, have no feedback and are never searched. A date with no Release Note is "page not
 * found".
 */
final class WhatsNewPages
{
    public function __construct(private readonly ContentLibrary $library) {}

    public function index(): View
    {
        return view('site.whats-new', ['notes' => $this->library->releaseNotes()]);
    }

    public function show(string $date): View
    {
        $note = $this->library->releaseNote($date);
        abort_if($note === null, 404);

        return view('site.release-note', ['note' => $note]);
    }
}

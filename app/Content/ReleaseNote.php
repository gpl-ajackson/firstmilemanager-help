<?php

namespace App\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Release Note's row in the database copy. Only the content library writes it, from the
 * content folder, on each rebuild; read Release Notes through ContentLibrary.
 *
 * @property CarbonImmutable $released_on The FMM Release's date, which is its address.
 * @property string $summary One sentence on what the release changed.
 * @property string $intro_html Words before the changes, rendered; empty when there are none.
 * @property list<array{area: string, changes: list<array{html: string, team_roles: list<string>, affects_clients: bool, articles: list<array{title: string, address: string}>}>}> $areas
 *                                                                                                                                                                                        Its changes grouped by Product Area in the app's menu order, each rendered once per rebuild.
 * @property bool $affects_clients Whether any of its changes affects what clients see.
 * @property string $source_path The file it was built from, relative to the content folder.
 */
class ReleaseNote extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'released_on';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'released_on' => 'immutable_date',
            'areas' => 'array',
            'affects_clients' => 'boolean',
        ];
    }

    /** The Release Note's address: /whats-new/<yyyy-mm-dd>. */
    public function address(): string
    {
        return ReleaseNoteSource::addressOf($this->released_on->toDateString());
    }

    /** "What’s new: 14 November 2026", the Release Note's title (plan, "Release Notes"). */
    public function title(): string
    {
        return 'What’s new: '.$this->dateInWords();
    }

    /** "14 November 2026". */
    public function dateInWords(): string
    {
        return $this->released_on->format('j F Y');
    }
}

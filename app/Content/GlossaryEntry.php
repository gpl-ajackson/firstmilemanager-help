<?php

namespace App\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Glossary Entry's row in the database copy, staff or client. Only the content library
 * writes it, from the content folder, on each rebuild; read entries through ContentLibrary.
 *
 * @property int $id
 * @property string $name The term's name in its address, from the file name: /glossary/<name>.
 * @property AudienceTier $audience_tier Staff (the Glossary) or Client (the Client Glossary).
 * @property string $term The term exactly as GLOSSARY.md names it.
 * @property list<string> $other_names Words readers use for it, picked by hand.
 * @property list<string> $former_names Names it had before GLOSSARY.md renamed it; their addresses redirect here.
 * @property string $where_you_see_it
 * @property list<array{value: string, meaning: string, address: ?string}> $status_values A status's values,
 *                                                                                        each with what it means and its own entry's address when it has one.
 * @property CarbonImmutable $checked_on The date it was last checked against GLOSSARY.md; never shown.
 * @property string $source_path The file it was built from, relative to the content folder.
 * @property string $body_html One or two plain sentences, rendered once per rebuild.
 */
class GlossaryEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'audience_tier' => AudienceTier::class,
            'other_names' => 'array',
            'former_names' => 'array',
            'status_values' => 'array',
            'checked_on' => 'immutable_date',
        ];
    }

    public function address(): string
    {
        return GlossaryEntrySource::addressOf($this->name, $this->audience_tier);
    }

    /**
     * What readers see after "Also called" (and what search maps to the entry): its Other
     * Names, then any former name not already among them, since a renamed term's old name is
     * a word readers used.
     *
     * @return list<string>
     */
    public function alsoCalled(): array
    {
        $names = $this->other_names;
        $lower = array_map(mb_strtolower(...), $names);
        foreach ($this->former_names as $former) {
            if (! in_array(mb_strtolower($former), $lower, true)) {
                $names[] = $former;
                $lower[] = mb_strtolower($former);
            }
        }

        return $names;
    }
}
